<?php

namespace Tests\Feature\Installation;

use App\Enums\WorkOrderStatus;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Technician;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderTechnician;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderClaimApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
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

    private function workOrder(Tenant $tenant, WorkOrderStatus $status = WorkOrderStatus::PendingVerification): WorkOrder
    {
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null]);

        return WorkOrder::factory()->forSubscription($subscription)->create(['status' => $status]);
    }

    public function test_claiming_an_unclaimed_work_order_succeeds(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $technician] = $this->technicianUser($tenant);
        $wo = $this->workOrder($tenant);

        $this->actingAs($user)
            ->postJson("/api/v1/work-orders/{$wo->id}/claim")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('work_order_technicians', [
            'work_order_id' => $wo->id,
            'technician_id' => $technician->id,
        ]);
    }

    public function test_claiming_the_same_work_order_twice_is_idempotent_not_an_error(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $technician] = $this->technicianUser($tenant);
        $wo = $this->workOrder($tenant);

        $this->actingAs($user)->postJson("/api/v1/work-orders/{$wo->id}/claim")->assertOk();
        $this->actingAs($user)->postJson("/api/v1/work-orders/{$wo->id}/claim")->assertOk();

        $this->assertSame(
            1,
            WorkOrderTechnician::where('work_order_id', $wo->id)->where('technician_id', $technician->id)->count(),
        );
    }

    public function test_claiming_a_completed_and_unclaimed_work_order_403s_via_policy_before_reaching_the_service(): void
    {
        $tenant = Tenant::factory()->create();
        [$user] = $this->technicianUser($tenant);
        $wo = $this->workOrder($tenant, WorkOrderStatus::Completed);

        // Completed + unclaimed -> policy view() sudah menolak (403) SEBELUM
        // sempat sampai ke pengecekan status di service — buktikan 403 di
        // sini, lalu buktikan 422 terpisah lewat kasus sudah-diklaim di bawah
        // (di mana view() lolos tapi service-nya sendiri yang menolak).
        $this->actingAs($user)->postJson("/api/v1/work-orders/{$wo->id}/claim")->assertForbidden();
    }

    public function test_claiming_a_work_order_that_became_completed_after_being_claimed_is_rejected_with_422(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $technician] = $this->technicianUser($tenant);
        $wo = $this->workOrder($tenant);

        WorkOrderTechnician::create(['work_order_id' => $wo->id, 'technician_id' => $technician->id, 'claimed_at' => now()]);
        $wo->update(['status' => WorkOrderStatus::Completed]);

        // view() masih lolos (dia sudah ada di pivot -> jalur assigned/claimed,
        // TIDAK melalui cabang "unclaimed" yang di-filter status) — tapi
        // WorkOrderService::claim() sendiri menolak re-klaim WO yang sudah
        // terminal.
        $this->actingAs($user)
            ->postJson("/api/v1/work-orders/{$wo->id}/claim")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_a_user_with_no_linked_technician_cannot_claim(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $wo = $this->workOrder($tenant);

        $this->actingAs($admin)
            ->postJson("/api/v1/work-orders/{$wo->id}/claim")
            ->assertForbidden();
    }

    public function test_a_technician_cannot_claim_a_work_order_already_assigned_to_another_technician(): void
    {
        $tenant = Tenant::factory()->create();
        [$userA] = $this->technicianUser($tenant);
        [, $technicianB] = $this->technicianUser($tenant);
        $wo = $this->workOrder($tenant, WorkOrderStatus::Assigned);
        $wo->update(['technician_id' => $technicianB->id]);

        $this->actingAs($userA)
            ->postJson("/api/v1/work-orders/{$wo->id}/claim")
            ->assertForbidden();
    }
}
