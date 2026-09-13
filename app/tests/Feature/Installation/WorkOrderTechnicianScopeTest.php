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
use App\Services\Installation\WorkOrderService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.12.3 — WorkOrderPolicy's dua jalur INDEPENDEN (Opsi C, keputusan
 * Agung): (1) `technician_id` real-time (assignment resmi admin, TIDAK
 * pernah disinkronkan ke pivot), (2) klaim mandiri di
 * `work_order_technicians`. WO unclaimed (technician_id null DAN belum
 * ada klaim) DAN belum completed/cancelled tetap terlihat semua pemegang
 * `work_orders.technician`.
 */
class WorkOrderTechnicianScopeTest extends TestCase
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

    private function workOrder(Tenant $tenant, WorkOrderStatus $status = WorkOrderStatus::PendingVerification, ?int $technicianId = null): WorkOrder
    {
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null]);

        return WorkOrder::factory()->forSubscription($subscription)->create([
            'status' => $status,
            'technician_id' => $technicianId,
        ]);
    }

    // ── viewAny/index scoping ──────────────────────────────────────────

    public function test_technician_sees_unclaimed_work_orders_in_the_index(): void
    {
        $tenant = Tenant::factory()->create();
        [$user] = $this->technicianUser($tenant);
        $wo = $this->workOrder($tenant);

        $response = $this->actingAs($user)->getJson('/api/v1/work-orders');

        $response->assertOk();
        $this->assertTrue(collect($response->json('data'))->pluck('id')->contains($wo->id));
    }

    public function test_technician_a_does_not_see_a_work_order_claimed_only_by_technician_b(): void
    {
        $tenant = Tenant::factory()->create();
        [$userA] = $this->technicianUser($tenant);
        [, $technicianB] = $this->technicianUser($tenant);
        $wo = $this->workOrder($tenant);

        WorkOrderTechnician::create(['work_order_id' => $wo->id, 'technician_id' => $technicianB->id, 'claimed_at' => now()]);

        $response = $this->actingAs($userA)->getJson('/api/v1/work-orders');

        $response->assertOk();
        $this->assertFalse(collect($response->json('data'))->pluck('id')->contains($wo->id));
    }

    public function test_technician_sees_a_work_order_they_claimed_even_when_another_also_claimed_it(): void
    {
        $tenant = Tenant::factory()->create();
        [$userA, $technicianA] = $this->technicianUser($tenant);
        [, $technicianB] = $this->technicianUser($tenant);
        $wo = $this->workOrder($tenant);

        WorkOrderTechnician::create(['work_order_id' => $wo->id, 'technician_id' => $technicianA->id, 'claimed_at' => now()]);
        WorkOrderTechnician::create(['work_order_id' => $wo->id, 'technician_id' => $technicianB->id, 'claimed_at' => now()]);

        $response = $this->actingAs($userA)->getJson('/api/v1/work-orders');

        $this->assertTrue(collect($response->json('data'))->pluck('id')->contains($wo->id));
    }

    public function test_completed_and_unclaimed_work_order_is_hidden_from_browsing(): void
    {
        $tenant = Tenant::factory()->create();
        [$user] = $this->technicianUser($tenant);
        $wo = $this->workOrder($tenant, WorkOrderStatus::Completed);

        $response = $this->actingAs($user)->getJson('/api/v1/work-orders');

        $this->assertFalse(collect($response->json('data'))->pluck('id')->contains($wo->id));
    }

    // ── assignment resmi (technician_id, real-time, tanpa sinkron pivot) ──

    public function test_technician_can_view_a_work_order_assigned_to_them_without_ever_claiming(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $technician] = $this->technicianUser($tenant);
        $wo = $this->workOrder($tenant, WorkOrderStatus::Assigned, $technician->id);

        $this->actingAs($user)->getJson("/api/v1/work-orders/{$wo->id}")->assertOk();

        // Tidak ada baris pivot sama sekali — akses murni dari technician_id.
        $this->assertSame(0, WorkOrderTechnician::where('work_order_id', $wo->id)->count());
    }

    public function test_reassigning_to_another_technician_immediately_revokes_the_old_ones_access(): void
    {
        $tenant = Tenant::factory()->create();
        [$userA, $technicianA] = $this->technicianUser($tenant);
        [$userB, $technicianB] = $this->technicianUser($tenant);
        $wo = $this->workOrder($tenant, WorkOrderStatus::Assigned, $technicianA->id);

        $this->actingAs($userA)->getJson("/api/v1/work-orders/{$wo->id}")->assertOk();

        // Admin re-assign (langsung update kolom, sama seperti
        // WorkOrderService::assignTechnician() — TIDAK menyentuh pivot).
        $wo->update(['technician_id' => $technicianB->id]);

        $this->actingAs($userA)->getJson("/api/v1/work-orders/{$wo->id}")->assertForbidden();
        $this->actingAs($userB)->getJson("/api/v1/work-orders/{$wo->id}")->assertOk();

        // Tidak ada baris pivot yang perlu "dibersihkan" — teknisi A belum
        // pernah klaim manual sama sekali.
        $this->assertSame(0, WorkOrderTechnician::where('work_order_id', $wo->id)->count());
    }

    // ── klaim mandiri (independen dari technician_id) ──────────────────

    public function test_technician_can_view_a_work_order_they_claimed_even_though_technician_id_is_not_them(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $technician] = $this->technicianUser($tenant);
        $wo = $this->workOrder($tenant); // technician_id null saat diklaim

        app(WorkOrderService::class)->claim($wo, $technician);

        $this->actingAs($user)->getJson("/api/v1/work-orders/{$wo->id}")->assertOk();
        $this->assertNotSame($technician->id, $wo->fresh()->technician_id);
    }

    // ── manage() TIDAK termasuk cabang "unclaimed" ─────────────────────

    public function test_technician_cannot_manage_an_unclaimed_work_order_they_have_not_claimed(): void
    {
        $tenant = Tenant::factory()->create();
        [$user] = $this->technicianUser($tenant);
        $wo = $this->workOrder($tenant, WorkOrderStatus::Ready);

        // view boleh (browsing), tapi start() (butuh authorize('manage', ...)) tidak.
        $this->actingAs($user)->getJson("/api/v1/work-orders/{$wo->id}")->assertOk();
        $this->actingAs($user)->postJson("/api/v1/work-orders/{$wo->id}/start")->assertForbidden();
    }

    public function test_technician_can_manage_a_work_order_assigned_to_them(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $technician] = $this->technicianUser($tenant);
        $wo = $this->workOrder($tenant, WorkOrderStatus::Assigned, $technician->id);

        $this->actingAs($user)->postJson("/api/v1/work-orders/{$wo->id}/start")->assertOk();
    }
}
