<?php

namespace Tests\Feature\Installation;

use App\Models\Customer;
use App\Models\Reseller;
use App\Models\Subscription;
use App\Models\Technician;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderResellerIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function resellerOwner(Tenant $tenant, Reseller $reseller): User
    {
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $reseller->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        return $owner;
    }

    public function test_api_index_only_returns_the_acting_resellers_own_work_orders(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        $customerA = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $resellerA->id]);
        $subscriptionA = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customerA->id, 'reseller_id' => $resellerA->id]);
        $workOrderA = WorkOrder::factory()->forSubscription($subscriptionA)->create();

        $customerB = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $resellerB->id]);
        $subscriptionB = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customerB->id, 'reseller_id' => $resellerB->id]);
        WorkOrder::factory()->forSubscription($subscriptionB)->create();

        $ownerA = $this->resellerOwner($tenant, $resellerA);

        $response = $this->actingAs($ownerA)->getJson('/api/v1/work-orders');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($workOrderA->id));
        $this->assertCount(1, $ids);
    }

    public function test_api_show_404s_for_another_resellers_work_order(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        $customerB = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $resellerB->id]);
        $subscriptionB = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customerB->id, 'reseller_id' => $resellerB->id]);
        $workOrderB = WorkOrder::factory()->forSubscription($subscriptionB)->create();

        $ownerA = $this->resellerOwner($tenant, $resellerA);

        $this->actingAs($ownerA)
            ->getJson("/api/v1/work-orders/{$workOrderB->id}")
            ->assertNotFound();
    }

    public function test_reseller_a_cannot_assign_technician_to_reseller_bs_work_order(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        $customerB = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $resellerB->id]);
        $subscriptionB = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customerB->id, 'reseller_id' => $resellerB->id]);
        $workOrderB = WorkOrder::factory()->forSubscription($subscriptionB)->ready()->create();

        $technicianB = Technician::factory()->forReseller($resellerB)->create();

        $ownerA = $this->resellerOwner($tenant, $resellerA);

        $this->actingAs($ownerA)
            ->postJson("/api/v1/work-orders/{$workOrderB->id}/assign", ['technician_id' => $technicianB->id])
            ->assertNotFound();
    }

    public function test_reseller_a_cannot_create_a_work_order_for_reseller_bs_subscription(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        $customerB = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $resellerB->id]);
        $subscriptionB = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customerB->id, 'reseller_id' => $resellerB->id]);

        $ownerA = $this->resellerOwner($tenant, $resellerA);

        // Subscription's own BelongsToResellerScope already excludes B's
        // subscription from A's route-model-binding lookup entirely — same
        // "404 via route-model-binding" pattern as TenantIsolationTest,
        // never reaching WorkOrderPolicy::create() at all.
        $this->actingAs($ownerA)
            ->postJson("/api/v1/subscriptions/{$subscriptionB->id}/work-order")
            ->assertNotFound();
    }
}
