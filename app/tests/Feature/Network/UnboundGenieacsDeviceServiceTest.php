<?php

namespace Tests\Feature\Network;

use App\Enums\CpeDeviceStatus;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\Tenant;
use App\Services\Network\UnboundGenieacsDeviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * UnboundGenieacsDeviceService — diff antara semua device di GenieACS
 * (bulk query, di-fake) dan baris cpe_devices. Semua HTTP di-fake, tidak
 * pernah menyentuh genieacs-nbi asli.
 */
class UnboundGenieacsDeviceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * @param  array<int, array<string, mixed>>  $devices
     */
    private function fakeGenieAcs(array $devices): void
    {
        Http::fake(['*genieacs-nbi*' => Http::response($devices, 200)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function device(string $serial, array $overrides = []): array
    {
        return array_replace_recursive([
            '_id' => "6C0F0B-H3-2S-{$serial}",
            '_deviceId' => [
                '_Manufacturer' => 'CMDC',
                '_OUI' => '6C0F0B',
                '_ProductClass' => 'H3-2S XPON',
                '_SerialNumber' => $serial,
            ],
            '_registered' => '2026-08-12T05:19:26.820Z',
            '_lastInform' => '2026-09-07T07:55:31.928Z',
            'InternetGatewayDevice' => [
                'ManagementServer' => ['URL' => ['_value' => 'http://genieacs.bajastu.id:7547']],
            ],
        ], $overrides);
    }

    private function service(): UnboundGenieacsDeviceService
    {
        return app(UnboundGenieacsDeviceService::class);
    }

    public function test_returns_only_genieacs_devices_without_a_cpe_device_binding(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);

        CpeDevice::factory()->create([
            'customer_id' => $customer->id,
            'genieacs_device_id' => '6C0F0B-H3-2S-BOUND0001',
            'serial_number' => 'BOUND0001',
        ]);

        $this->fakeGenieAcs([
            $this->device('BOUND0001'),
            $this->device('UNBOUND01'),
            $this->device('UNBOUND02'),
        ]);

        $list = $this->service()->list();

        $serials = array_column($list, 'serial_number');
        $this->assertEqualsCanonicalizing(['UNBOUND01', 'UNBOUND02'], $serials);
    }

    public function test_probe_pseudo_device_is_filtered_out(): void
    {
        $this->fakeGenieAcs([
            [
                '_id' => '000000-probe-000000000001',
                '_deviceId' => ['_Manufacturer' => 'probe', '_OUI' => '000000', '_ProductClass' => 'probe', '_SerialNumber' => '000000000001'],
                '_lastInform' => '2026-09-07T07:00:00.000Z',
            ],
            $this->device('REALDEV01'),
        ]);

        $list = $this->service()->list();

        $this->assertSame(['REALDEV01'], array_column($list, 'serial_number'));
    }

    public function test_serial_already_known_in_boss_is_flagged_with_the_customer_name(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Budi Santoso']);

        // A pending_first_connect row: BOSS App knows the serial, but it is
        // not yet linked to a GenieACS _id.
        CpeDevice::factory()->create([
            'customer_id' => $customer->id,
            'genieacs_device_id' => null,
            'serial_number' => 'PENDING01',
            'status' => CpeDeviceStatus::PendingFirstConnect,
        ]);

        $this->fakeGenieAcs([
            $this->device('PENDING01'),
            $this->device('STRANGER1'),
        ]);

        $list = collect($this->service()->list())->keyBy('serial_number');

        $this->assertSame('serial_known', $list['PENDING01']['boss_state']);
        $this->assertSame('Budi Santoso', $list['PENDING01']['boss_customer']);
        $this->assertSame('unknown', $list['STRANGER1']['boss_state']);
        $this->assertNull($list['STRANGER1']['boss_customer']);
    }

    public function test_bound_check_ignores_tenant_scope(): void
    {
        // Device bound under a tenant nobody in this request belongs to —
        // still counts as bound, must not appear as "unbound".
        $otherTenant = Tenant::factory()->create();
        $otherCustomer = Customer::factory()->create(['tenant_id' => $otherTenant->id]);

        CpeDevice::factory()->create([
            'customer_id' => $otherCustomer->id,
            'genieacs_device_id' => '6C0F0B-H3-2S-OTHERTNT1',
            'serial_number' => 'OTHERTNT1',
        ]);

        $this->fakeGenieAcs([$this->device('OTHERTNT1')]);

        $this->assertSame([], $this->service()->list());
    }

    public function test_extracts_mac_acs_url_and_timestamps(): void
    {
        $this->fakeGenieAcs([
            $this->device('DEV0001', [
                '_registered' => '2026-09-01T10:00:00.000Z',
                '_lastInform' => '2026-09-07T08:30:00.000Z',
                'InternetGatewayDevice' => [
                    'WANDevice' => ['1' => ['WANConnectionDevice' => ['1' => ['WANPPPConnection' => ['1' => [
                        'MACAddress' => ['_value' => 'F4:4C:7F:70:12:86'],
                    ]]]]]],
                ],
            ]),
            // No URL -> acs_url null (Option 43 not read / manual)
            $this->device('DEV0002', ['InternetGatewayDevice' => ['ManagementServer' => ['URL' => ['_value' => '']]]]),
        ]);

        $list = collect($this->service()->list())->keyBy('serial_number');

        $this->assertSame('F4:4C:7F:70:12:86', $list['DEV0001']['mac_address']);
        $this->assertSame('http://genieacs.bajastu.id:7547', $list['DEV0001']['acs_url']);
        $this->assertStringStartsWith('2026-09-01T10:00:00', $list['DEV0001']['registered_at']);
        $this->assertStringStartsWith('2026-09-07T08:30:00', $list['DEV0001']['last_inform_at']);

        $this->assertNull($list['DEV0002']['mac_address']);
        $this->assertNull($list['DEV0002']['acs_url']);
    }

    public function test_sorted_by_last_inform_descending(): void
    {
        $this->fakeGenieAcs([
            $this->device('OLD', ['_lastInform' => '2026-09-01T00:00:00.000Z']),
            $this->device('NEW', ['_lastInform' => '2026-09-07T00:00:00.000Z']),
            $this->device('MID', ['_lastInform' => '2026-09-04T00:00:00.000Z']),
        ]);

        $this->assertSame(['NEW', 'MID', 'OLD'], array_column($this->service()->list(), 'serial_number'));
    }

    public function test_caches_the_bulk_genieacs_query_across_calls(): void
    {
        config(['services.genieacs.unbound_cache_ttl' => 30]);
        $this->fakeGenieAcs([$this->device('DEV0001')]);

        $this->service()->list();
        $this->service()->list();

        Http::assertSentCount(1);
    }

    public function test_ttl_zero_disables_the_cache(): void
    {
        config(['services.genieacs.unbound_cache_ttl' => 0]);
        $this->fakeGenieAcs([$this->device('DEV0001')]);

        $this->service()->list();
        $this->service()->list();

        Http::assertSentCount(2);
    }
}
