<?php

namespace Tests\Feature\Installation;

use App\Models\Customer;
use App\Models\Reseller;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * v0.26.1 — wiring work_orders.scheduled_at ke POST /api/v1/work-orders
 * (StoreWorkOrderRequest). Nol dispatch/notification logic di sini —
 * murni memastikan janji hari+jam kunjungan bisa masuk lewat titik create
 * dan tersimpan apa adanya. Sengaja tidak pakai OdpPort/subscriptionWithNearbyOdp
 * — WorkOrder tetap dibuat (dan scheduled_at tetap ter-set) terlepas dari
 * hasil pencarian ODP itu sendiri (lihat WorkOrderService::createFromSubscription()).
 */
class WorkOrderScheduledAtApiTest extends TestCase
{
    use RefreshDatabase;

    private function resellerOwnerWithSubscription(): array
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'reseller_id' => $reseller->id,
        ]);
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $reseller->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        return [$owner, $subscription];
    }

    public function test_creating_a_work_order_with_scheduled_at_persists_it(): void
    {
        [$owner, $subscription] = $this->resellerOwnerWithSubscription();

        $response = $this->actingAs($owner)->postJson('/api/v1/work-orders', [
            'subscription_id' => $subscription->id,
            'scheduled_at' => '2026-10-10 08:30:00',
        ]);

        $response->assertCreated();
        // Timezone-agnostic — cuma memastikan instant-nya sama, bukan
        // asumsi offset tertentu (app.timezone bisa beda antara dev
        // Postgres dan sqlite test connection).
        $this->assertTrue(Carbon::parse($response->json('data.scheduled_at'))->equalTo(Carbon::parse('2026-10-10 08:30:00')));

        $workOrder = WorkOrder::withoutGlobalScopes()->where('subscription_id', $subscription->id)->firstOrFail();
        $this->assertTrue($workOrder->scheduled_at->equalTo(Carbon::parse('2026-10-10 08:30:00')));
    }

    public function test_creating_a_work_order_without_scheduled_at_is_still_valid(): void
    {
        [$owner, $subscription] = $this->resellerOwnerWithSubscription();

        $response = $this->actingAs($owner)->postJson('/api/v1/work-orders', [
            'subscription_id' => $subscription->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.scheduled_at', null);

        $workOrder = WorkOrder::withoutGlobalScopes()->where('subscription_id', $subscription->id)->firstOrFail();
        $this->assertNull($workOrder->scheduled_at);
    }

    public function test_an_invalid_scheduled_at_value_is_rejected_with_422(): void
    {
        [$owner, $subscription] = $this->resellerOwnerWithSubscription();

        $response = $this->actingAs($owner)->postJson('/api/v1/work-orders', [
            'subscription_id' => $subscription->id,
            'scheduled_at' => 'bukan-tanggal',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['scheduled_at']);
        $this->assertSame(0, WorkOrder::withoutGlobalScopes()->where('subscription_id', $subscription->id)->count());
    }
}
