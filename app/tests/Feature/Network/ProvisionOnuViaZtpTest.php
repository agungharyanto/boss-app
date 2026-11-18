<?php

namespace Tests\Feature\Network;

use App\Enums\WhatsappEventType;
use App\Enums\WorkOrderDeviceType;
use App\Enums\WorkOrderStatus;
use App\Enums\ZtpStatus;
use App\Jobs\ProvisionOnuViaZtp;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\OltDevice;
use App\Models\OltManufacturer;
use App\Models\OltModel;
use App\Models\Technician;
use App\Models\Tenant;
use App\Models\WhatsappMessageTemplate;
use App\Models\WhatsappNotificationGroup;
use App\Models\WorkOrder;
use App\Models\WorkOrderDevice;
use App\Services\Network\Provisioning\OnuDiscoveryService;
use App\Services\Network\Provisioning\ZtpOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * v0.13.4.2.3 — Job pemicu OMCI/ZTP. Mock OnuDiscoveryService + ZtpOrchestratorService
 * (NOL sentuh perangkat/sidecar/GenieACS).
 */
class ProvisionOnuViaZtpTest extends TestCase
{
    use RefreshDatabase;

    private function olt(int $tenantId, string $manu, string $model, array $overrides = []): OltDevice
    {
        $manufacturer = OltManufacturer::factory()->create(['name' => $manu]);
        $oltModel = OltModel::factory()->create(['name' => $model, 'olt_manufacturer_id' => $manufacturer->id]);

        return OltDevice::factory()->create(array_merge([
            'tenant_id' => $tenantId,
            'reseller_id' => null,
            'olt_model_id' => $oltModel->id,
            'requires_bind_onu' => false,
            'omci_channel_enabled' => true,
            'tr069_channel_enabled' => true,
        ], $overrides));
    }

    /** WO InProgress + ONT device + CPE + customer, ztp pending (siap di-provision). */
    private function workOrder(int $tenantId, array $woOverrides = [], array $cpeOverrides = []): array
    {
        $customer = Customer::factory()->create(['tenant_id' => $tenantId, 'reseller_id' => null, 'cid' => '12345']);
        $tech = Technician::factory()->create(['tenant_id' => $tenantId]);
        $wo = WorkOrder::factory()->create(array_merge([
            'tenant_id' => $tenantId,
            'reseller_id' => null,
            'customer_id' => $customer->id,
            'status' => WorkOrderStatus::InProgress,
            'claimed_at' => now(),
            'claimed_by_technician_id' => $tech->id,
            'ztp_status' => ZtpStatus::Pending->value,
        ], $woOverrides));
        $device = WorkOrderDevice::create([
            'work_order_id' => $wo->id,
            'device_type' => WorkOrderDeviceType::Ont,
            'serial_number' => 'SN-ABC-1',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'scanned_at' => now(),
        ]);
        $cpe = CpeDevice::factory()->create(array_merge([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'work_order_device_id' => $device->id,
            'serial_number' => 'SN-ABC-1',
            'genieacs_device_id' => null,
        ], $cpeOverrides));

        return compact('customer', 'tech', 'wo', 'device', 'cpe');
    }

    private function fakeDiscovery(array $result): void
    {
        $m = Mockery::mock(OnuDiscoveryService::class);
        $m->shouldReceive('discover')->andReturn($result);
        $this->app->instance(OnuDiscoveryService::class, $m);
    }

    private function fakeOrchestrator(array $result): void
    {
        $m = Mockery::mock(ZtpOrchestratorService::class);
        $m->shouldReceive('provision')->andReturn($result);
        $this->app->instance(ZtpOrchestratorService::class, $m);
    }

    // ── happy ────────────────────────────────────────────────────────────

    public function test_success_marks_provisioned(): void
    {
        $tenant = Tenant::factory()->create();
        $olt = $this->olt($tenant->id, 'HSGQ', 'HSGQ-G02ID');
        ['wo' => $wo] = $this->workOrder($tenant->id);

        $this->fakeDiscovery(['found' => true, 'olt_id' => $olt->id, 'pon' => 'gpon 1', 'status' => 'new', 'identifier' => 'SN-ABC-1']);
        $this->fakeOrchestrator(['ok' => true, 'stages' => [['stage' => 'wan_internet', 'ok' => true]]]);

        ProvisionOnuViaZtp::dispatchSync($wo->id);

        $wo->refresh();
        $this->assertSame(ZtpStatus::Success->value, $wo->ztp_status);
        $this->assertNotNull($wo->ztp_provisioned_at);
        $this->assertSame($olt->id, $wo->ztp_olt_device_id);
    }

    public function test_idempotent_already_provisioned_is_noop(): void
    {
        $tenant = Tenant::factory()->create();
        ['wo' => $wo] = $this->workOrder($tenant->id, ['ztp_status' => ZtpStatus::Success->value, 'ztp_provisioned_at' => now()->subHour()]);

        // Discovery/orchestrator TIDAK boleh dipanggil.
        $disc = Mockery::mock(OnuDiscoveryService::class);
        $disc->shouldNotReceive('discover');
        $this->app->instance(OnuDiscoveryService::class, $disc);

        ProvisionOnuViaZtp::dispatchSync($wo->id);
        $this->assertTrue(true); // tak ada panggilan discover = lolos
    }

    // ── gate / guard ─────────────────────────────────────────────────────

    public function test_zte_omci_gate_off_is_skipped_not_run(): void
    {
        config(['services.olt_sidecar.ztp_omci_activation_enabled' => false]);
        $tenant = Tenant::factory()->create();
        $zte = $this->olt($tenant->id, 'ZTE', 'C300', ['requires_bind_onu' => null, 'omci_channel_enabled' => true]);
        ['wo' => $wo] = $this->workOrder($tenant->id);

        $this->fakeDiscovery(['found' => true, 'olt_id' => $zte->id, 'pon' => null, 'status' => 'uncfg', 'identifier' => 'SN-ABC-1']);
        // Orchestrator TIDAK boleh dipanggil untuk ZTE yang di-gate.
        $orch = Mockery::mock(ZtpOrchestratorService::class);
        $orch->shouldNotReceive('provision');
        $this->app->instance(ZtpOrchestratorService::class, $orch);

        ProvisionOnuViaZtp::dispatchSync($wo->id);

        $wo->refresh();
        $this->assertSame(ZtpStatus::Skipped->value, $wo->ztp_status);
    }

    public function test_channel_disabled_is_skipped(): void
    {
        $tenant = Tenant::factory()->create();
        $olt = $this->olt($tenant->id, 'HSGQ', 'HSGQ-G02ID', ['tr069_channel_enabled' => false]);
        ['wo' => $wo] = $this->workOrder($tenant->id);

        $this->fakeDiscovery(['found' => true, 'olt_id' => $olt->id, 'pon' => 'gpon 1', 'status' => 'new', 'identifier' => 'SN-ABC-1']);
        $orch = Mockery::mock(ZtpOrchestratorService::class);
        $orch->shouldNotReceive('provision');
        $this->app->instance(ZtpOrchestratorService::class, $orch);

        ProvisionOnuViaZtp::dispatchSync($wo->id);

        $this->assertSame(ZtpStatus::Skipped->value, $wo->refresh()->ztp_status);
    }

    public function test_sn_active_elsewhere_is_rejected_and_notifies_group(): void
    {
        $tenant = Tenant::factory()->create();
        $olt = $this->olt($tenant->id, 'HSGQ', 'HSGQ-G02ID');
        ['wo' => $wo, 'customer' => $customer] = $this->workOrder($tenant->id);

        // CPE LAIN dgn SN sama, sudah online (genieacs_device_id terisi) = aktif.
        CpeDevice::factory()->create([
            'tenant_id' => $tenant->id, 'customer_id' => Customer::factory()->create(['tenant_id' => $tenant->id])->id,
            'serial_number' => 'SN-ABC-1', 'genieacs_device_id' => 'EXISTING-DEVICE',
        ]);

        // Grup + template agar event WA tercatat.
        $this->setupGroup($tenant->id);

        $this->fakeDiscovery(['found' => true, 'olt_id' => $olt->id, 'pon' => 'gpon 1', 'status' => 'new', 'identifier' => 'SN-ABC-1']);

        ProvisionOnuViaZtp::dispatchSync($wo->id);

        $wo->refresh();
        $this->assertSame(ZtpStatus::Failed->value, $wo->ztp_status);
        $this->assertDatabaseHas('whatsapp_message_logs', [
            'event_type' => WhatsappEventType::OnuProvisioningFailed->value,
        ]);
    }

    // ── discovery outcomes ───────────────────────────────────────────────

    public function test_discovery_multiple_fails_and_notifies(): void
    {
        $tenant = Tenant::factory()->create();
        ['wo' => $wo] = $this->workOrder($tenant->id);
        $this->setupGroup($tenant->id);

        $this->fakeDiscovery(['found' => false, 'reason' => 'multiple', 'detail' => ['olt_ids' => [1, 2]]]);

        ProvisionOnuViaZtp::dispatchSync($wo->id);

        $this->assertSame(ZtpStatus::Failed->value, $wo->refresh()->ztp_status);
        $this->assertDatabaseHas('whatsapp_message_logs', ['event_type' => WhatsappEventType::OnuProvisioningFailed->value]);
    }

    public function test_provision_failure_does_not_rollback_activation(): void
    {
        $tenant = Tenant::factory()->create();
        $olt = $this->olt($tenant->id, 'HSGQ', 'HSGQ-G02ID');
        ['wo' => $wo] = $this->workOrder($tenant->id);
        $this->setupGroup($tenant->id);

        $this->fakeDiscovery(['found' => true, 'olt_id' => $olt->id, 'pon' => 'gpon 1', 'status' => 'new', 'identifier' => 'SN-ABC-1']);
        $this->fakeOrchestrator(['ok' => false, 'stage' => 'wan_internet', 'reason' => 'OLT timeout']);

        ProvisionOnuViaZtp::dispatchSync($wo->id);

        $wo->refresh();
        // WO tetap InProgress (aktivasi TIDAK di-rollback), hanya ZTP gagal.
        $this->assertSame(WorkOrderStatus::InProgress, $wo->status);
        $this->assertSame(ZtpStatus::Failed->value, $wo->ztp_status);
        $this->assertSame('wan_internet', $wo->ztp_last_result['failed_stage'] ?? null);
    }

    private function setupGroup(int $tenantId): void
    {
        WhatsappNotificationGroup::create([
            'tenant_id' => $tenantId, 'session_key' => 'direct', 'jid' => '123@g.us',
            'label' => 'NOC', 'is_default' => true, 'is_active' => true,
        ]);
        WhatsappMessageTemplate::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'reseller_id' => null,
            'event_type' => WhatsappEventType::OnuProvisioningFailed->value,
            'content' => 'WO #{work_order_id} gagal: {reason}', 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
