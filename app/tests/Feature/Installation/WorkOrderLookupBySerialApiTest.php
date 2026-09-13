<?php

namespace Tests\Feature\Installation;

use App\Enums\WorkOrderStatus;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Technician;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderDevice;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderLookupBySerialApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(Tenant $tenant): User
    {
        $u = User::factory()->create(['tenant_id' => $tenant->id]);
        $u->assignRole('superadmin');

        return $u;
    }

    /**
     * @return array{0: User, 1: Technician}
     */
    private function technicianUser(Tenant $tenant): array
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('teknisi');
        $technician = Technician::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

        return [$user, $technician];
    }

    private function workOrderWithDevice(Tenant $tenant, string $serial, WorkOrderStatus $status = WorkOrderStatus::PendingVerification, ?int $technicianId = null): WorkOrder
    {
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null]);
        $wo = WorkOrder::factory()->forSubscription($subscription)->create(['status' => $status, 'technician_id' => $technicianId]);
        WorkOrderDevice::factory()->forWorkOrder($wo)->create(['serial_number' => $serial]);

        return $wo;
    }

    public function test_found_by_serial_returns_the_work_order(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $wo = $this->workOrderWithDevice($tenant, 'ZTEG12345678');

        $this->actingAs($admin)
            ->getJson('/api/v1/work-orders/lookup-by-serial?serial=ZTEG12345678')
            ->assertOk()
            ->assertJsonPath('data.id', $wo->id);
    }

    public function test_not_found_serial_returns_404(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);

        $this->actingAs($admin)
            ->getJson('/api/v1/work-orders/lookup-by-serial?serial=TIDAK-ADA')
            ->assertNotFound();
    }

    public function test_missing_serial_parameter_is_rejected_with_422(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);

        $this->actingAs($admin)
            ->getJson('/api/v1/work-orders/lookup-by-serial')
            ->assertStatus(422);
    }

    public function test_technician_can_find_an_unclaimed_work_order_by_serial(): void
    {
        $tenant = Tenant::factory()->create();
        [$user] = $this->technicianUser($tenant);
        $wo = $this->workOrderWithDevice($tenant, 'ZTEG99999999');

        $this->actingAs($user)
            ->getJson('/api/v1/work-orders/lookup-by-serial?serial=ZTEG99999999')
            ->assertOk()
            ->assertJsonPath('data.id', $wo->id);
    }

    public function test_technician_lookup_403s_for_a_serial_assigned_to_another_technician(): void
    {
        $tenant = Tenant::factory()->create();
        [$userA] = $this->technicianUser($tenant);
        [, $technicianB] = $this->technicianUser($tenant);
        $this->workOrderWithDevice($tenant, 'ZTEG11112222', WorkOrderStatus::Assigned, $technicianB->id);

        $this->actingAs($userA)
            ->getJson('/api/v1/work-orders/lookup-by-serial?serial=ZTEG11112222')
            ->assertForbidden();
    }

    public function test_serial_belonging_to_another_tenant_is_not_found(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $adminA = $this->admin($tenantA);
        $this->workOrderWithDevice($tenantB, 'CROSS-TENANT-SN');

        $this->actingAs($adminA)
            ->getJson('/api/v1/work-orders/lookup-by-serial?serial=CROSS-TENANT-SN')
            ->assertNotFound();
    }
}
