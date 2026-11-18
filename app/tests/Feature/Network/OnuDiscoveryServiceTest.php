<?php

namespace Tests\Feature\Network;

use App\Models\Customer;
use App\Models\OltDevice;
use App\Models\OltManufacturer;
use App\Models\OltModel;
use App\Models\Tenant;
use App\Models\WorkOrder;
use App\Services\Network\OltSidecarClient;
use App\Services\Network\Provisioning\OnuDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * v0.13.4.2.3 — penemuan ONU lewat pembacaan OLT. Mock OltSidecarClient (NOL
 * sentuh perangkat). Fokus: ketemu 1 / tidak ketemu / ganda / beda tenant /
 * MAC kosong E04ID dilewati.
 */
class OnuDiscoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function olt(int $tenantId, string $manu, string $model, array $overrides = []): OltDevice
    {
        $manufacturer = OltManufacturer::factory()->create(['name' => $manu]);
        $oltModel = OltModel::factory()->create(['name' => $model, 'olt_manufacturer_id' => $manufacturer->id]);

        return OltDevice::factory()->create(array_merge([
            'tenant_id' => $tenantId, 'reseller_id' => null, 'olt_model_id' => $oltModel->id,
            'requires_bind_onu' => false, 'omci_channel_enabled' => true, 'tr069_channel_enabled' => true,
        ], $overrides));
    }

    private function wo(int $tenantId): WorkOrder
    {
        $customer = Customer::factory()->create(['tenant_id' => $tenantId, 'reseller_id' => null]);

        return WorkOrder::factory()->create(['tenant_id' => $tenantId, 'reseller_id' => null, 'customer_id' => $customer->id, 'odp_id' => null]);
    }

    private function service(OltSidecarClient $client): OnuDiscoveryService
    {
        return new OnuDiscoveryService($client);
    }

    public function test_found_in_exactly_one_g02id(): void
    {
        $tenant = Tenant::factory()->create();
        $olt = $this->olt($tenant->id, 'HSGQ', 'HSGQ-G02ID');

        $client = Mockery::mock(OltSidecarClient::class);
        $client->shouldReceive('resolveOnuBySn')->andReturn(['pon' => 'gpon 1', 'onu_id' => 7, 'state' => 'offline']);

        $res = $this->service($client)->discover($this->wo($tenant->id), 'SN-X', null);

        $this->assertTrue($res['found']);
        $this->assertSame($olt->id, $res['olt_id']);
        $this->assertSame('gpon 1', $res['pon']);
    }

    public function test_not_found(): void
    {
        $tenant = Tenant::factory()->create();
        $this->olt($tenant->id, 'HSGQ', 'HSGQ-G02ID');

        $client = Mockery::mock(OltSidecarClient::class);
        $client->shouldReceive('resolveOnuBySn')->andReturn(['pon' => null, 'onu_id' => null]);

        $res = $this->service($client)->discover($this->wo($tenant->id), 'SN-X', null);

        $this->assertFalse($res['found']);
        $this->assertSame('not_found', $res['reason']);
    }

    public function test_multiple_matches_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $this->olt($tenant->id, 'HSGQ', 'HSGQ-G02ID');
        // OLT kedua vendor ZTE, cocok juga (requires_bind_onu non-null agar jadi
        // kandidat discovery — nilai tak relevan utk matchZte/uncfg).
        $this->olt($tenant->id, 'ZTE', 'C300', ['requires_bind_onu' => false]);

        $client = Mockery::mock(OltSidecarClient::class);
        $client->shouldReceive('resolveOnuBySn')->andReturn(['onu_id' => 3, 'pon' => 'gpon 1']);
        $client->shouldReceive('onuUncfgList')->andReturn(['lines' => ['SN-X uncfg']]);

        $res = $this->service($client)->discover($this->wo($tenant->id), 'SN-X', null);

        $this->assertFalse($res['found']);
        $this->assertSame('multiple', $res['reason']);
    }

    public function test_different_tenant_olt_never_considered(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        // OLT ada di tenant B; WO di tenant A.
        $this->olt($tenantB->id, 'HSGQ', 'HSGQ-G02ID');

        $client = Mockery::mock(OltSidecarClient::class);
        $client->shouldNotReceive('resolveOnuBySn'); // tak ada OLT tenant A

        $res = $this->service($client)->discover($this->wo($tenantA->id), 'SN-X', null);

        $this->assertFalse($res['found']);
        $this->assertSame('no_tenant_olt', $res['reason']);
    }

    public function test_e04id_skipped_when_mac_empty(): void
    {
        $tenant = Tenant::factory()->create();
        $this->olt($tenant->id, 'HSGQ', 'HSGQ-E04ID', ['requires_bind_onu' => true]);

        $client = Mockery::mock(OltSidecarClient::class);
        $client->shouldNotReceive('showOnuInfoMac'); // MAC kosong -> tak dibaca

        $res = $this->service($client)->discover($this->wo($tenant->id), 'SN-X', null);

        $this->assertFalse($res['found']);
        $this->assertNotEmpty($res['skipped']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
