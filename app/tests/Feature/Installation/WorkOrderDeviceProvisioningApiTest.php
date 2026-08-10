<?php

namespace Tests\Feature\Installation;

use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderDeviceProvisioningApiTest extends TestCase
{
    use RefreshDatabase;

    private function resellerOwner(Tenant $tenant, Reseller $reseller): User
    {
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $reseller->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        return $owner;
    }

    public function test_reseller_owner_can_record_ssid_and_password_for_their_own_work_order_device(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);
        $device = WorkOrderDevice::factory()->forWorkOrder($workOrder)->create();
        $owner = $this->resellerOwner($tenant, $reseller);

        $response = $this->actingAs($owner)->patchJson(
            "/api/v1/work-orders/{$workOrder->id}/devices/{$device->id}/provisioning",
            ['ssid' => 'RumahBaru', 'wifi_password' => 'password123']
        );

        $response->assertOk();
        $this->assertDatabaseHas('work_order_devices', ['id' => $device->id, 'ssid' => 'RumahBaru']);
        $this->assertSame('password123', $device->fresh()->wifi_password);
    }

    public function test_requires_at_least_one_of_ssid_or_wifi_password(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);
        $device = WorkOrderDevice::factory()->forWorkOrder($workOrder)->create();
        $owner = $this->resellerOwner($tenant, $reseller);

        $this->actingAs($owner)
            ->patchJson("/api/v1/work-orders/{$workOrder->id}/devices/{$device->id}/provisioning", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ssid']);
    }

    /**
     * Genuine partial update — recording SSID in one call must never wipe
     * out a password recorded in an earlier, separate call (the realistic
     * scenario: SSID and password often arrive across two different phone
     * calls with the technician).
     */
    public function test_updating_ssid_only_does_not_clear_an_already_recorded_password(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);
        $device = WorkOrderDevice::factory()->forWorkOrder($workOrder)->withWifiCredentials('OldSsid', 'oldpassword1')->create();
        $owner = $this->resellerOwner($tenant, $reseller);

        $this->actingAs($owner)
            ->patchJson("/api/v1/work-orders/{$workOrder->id}/devices/{$device->id}/provisioning", ['ssid' => 'NewSsid'])
            ->assertOk();

        $device->refresh();
        $this->assertSame('NewSsid', $device->ssid);
        $this->assertSame('oldpassword1', $device->wifi_password);
    }

    public function test_device_belonging_to_a_different_work_order_404s(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $workOrderA = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);
        $workOrderB = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);
        $deviceB = WorkOrderDevice::factory()->forWorkOrder($workOrderB)->create();
        $owner = $this->resellerOwner($tenant, $reseller);

        $this->actingAs($owner)
            ->patchJson("/api/v1/work-orders/{$workOrderA->id}/devices/{$deviceB->id}/provisioning", ['ssid' => 'X'])
            ->assertNotFound();
    }

    public function test_reseller_cannot_provision_another_resellers_work_order_device(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $workOrderB = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $resellerB->id]);
        $deviceB = WorkOrderDevice::factory()->forWorkOrder($workOrderB)->create();
        $ownerA = $this->resellerOwner($tenant, $resellerA);

        $this->actingAs($ownerA)
            ->patchJson("/api/v1/work-orders/{$workOrderB->id}/devices/{$deviceB->id}/provisioning", ['ssid' => 'X'])
            ->assertNotFound();
    }
}
