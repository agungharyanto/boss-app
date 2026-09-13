<?php

namespace Tests\Feature\Installation;

use App\Models\ModemType;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.12.7 Langkah 1 — POST /work-orders/{wo}/devices, fokus khusus field
 * `modem_type_id` yang BARU (kolom work_order_devices.modem_type_id sudah
 * ada sejak v0.12.4, wiring endpoint+service ditutup di sini). Field-field
 * lama (device_type/mac_address/serial_number) sudah punya cakupan tidak
 * langsung lewat WorkOrderServiceTest (level service) — file ini
 * melengkapi lapisan HTTP/validasi yang sebelumnya belum ada test-nya
 * sama sekali untuk endpoint ini.
 */
class WorkOrderStoreDeviceApiTest extends TestCase
{
    use RefreshDatabase;

    private function resellerOwner(Tenant $tenant, Reseller $reseller): User
    {
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $reseller->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        return $owner;
    }

    private function baseFields(): array
    {
        return [
            'device_type' => 'ont',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'serial_number' => 'SN-TEST-001',
        ];
    }

    public function test_device_is_recorded_with_a_valid_modem_type_id(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);
        $owner = $this->resellerOwner($tenant, $reseller);

        $response = $this->actingAs($owner)->postJson(
            "/api/v1/work-orders/{$workOrder->id}/devices",
            [...$this->baseFields(), 'modem_type_id' => $modemType->id]
        );

        $response->assertCreated();
        $this->assertDatabaseHas('work_order_devices', [
            'work_order_id' => $workOrder->id,
            'serial_number' => 'SN-TEST-001',
            'modem_type_id' => $modemType->id,
        ]);
    }

    public function test_modem_type_id_is_optional_and_defaults_to_null(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);
        $owner = $this->resellerOwner($tenant, $reseller);

        $response = $this->actingAs($owner)->postJson(
            "/api/v1/work-orders/{$workOrder->id}/devices",
            $this->baseFields()
        );

        $response->assertCreated();
        $this->assertDatabaseHas('work_order_devices', [
            'work_order_id' => $workOrder->id,
            'serial_number' => 'SN-TEST-001',
            'modem_type_id' => null,
        ]);
    }

    public function test_a_nonexistent_modem_type_id_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);
        $owner = $this->resellerOwner($tenant, $reseller);

        $this->actingAs($owner)
            ->postJson("/api/v1/work-orders/{$workOrder->id}/devices", [...$this->baseFields(), 'modem_type_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['modem_type_id']);
    }

    public function test_an_inactive_modem_type_id_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);
        $inactiveModemType = ModemType::factory()->create(['tenant_id' => $tenant->id, 'is_active' => false]);
        $owner = $this->resellerOwner($tenant, $reseller);

        $this->actingAs($owner)
            ->postJson("/api/v1/work-orders/{$workOrder->id}/devices", [...$this->baseFields(), 'modem_type_id' => $inactiveModemType->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['modem_type_id']);
    }

    public function test_a_modem_type_id_from_another_tenant_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);

        $otherTenant = Tenant::factory()->create();
        $otherTenantModemType = ModemType::factory()->create(['tenant_id' => $otherTenant->id, 'is_active' => true]);

        $owner = $this->resellerOwner($tenant, $reseller);

        $this->actingAs($owner)
            ->postJson("/api/v1/work-orders/{$workOrder->id}/devices", [...$this->baseFields(), 'modem_type_id' => $otherTenantModemType->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['modem_type_id']);
    }
}
