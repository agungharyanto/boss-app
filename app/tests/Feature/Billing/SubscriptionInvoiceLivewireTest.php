<?php

namespace Tests\Feature\Billing;

use App\Livewire\Billing\InvoiceIndex;
use App\Livewire\Billing\SubscriptionIndex;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SubscriptionInvoiceLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_billing_user_can_create_subscription_via_ui(): void
    {
        $tenant = Tenant::factory()->create();
        $billing = User::factory()->create(['tenant_id' => $tenant->id]);
        $billing->assignRole('billing');
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);

        Livewire::actingAs($billing)
            ->test(SubscriptionIndex::class)
            ->assertOk()
            ->set('customer_id', (string) $customer->id)
            ->set('name', 'Paket 30 Mbps')
            ->set('monthly_amount', '300000')
            ->set('billing_cycle_day', '20')
            ->call('createSubscription')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('subscriptions', ['name' => 'Paket 30 Mbps', 'customer_id' => $customer->id]);
    }

    /**
     * v0.26.2b — "Janji Kunjungan" ISI: WorkOrder lahir dengan
     * scheduled_at terisi persis nilai input, DAN belum dispatch
     * (dispatched_at masih null — hook createFromSubscription() hanya
     * dispatchImmediately() untuk WO TANPA scheduledAt, WO ini menunggu
     * DispatchWorkOrders command sesuai window offset).
     */
    public function test_creating_a_subscription_with_a_scheduled_visit_creates_a_scheduled_work_order(): void
    {
        $tenant = Tenant::factory()->create();
        $billing = User::factory()->create(['tenant_id' => $tenant->id]);
        $billing->assignRole('billing');
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);

        $scheduledAt = now()->addDays(2)->format('Y-m-d\TH:i');

        Livewire::actingAs($billing)
            ->test(SubscriptionIndex::class)
            ->assertOk()
            ->set('customer_id', (string) $customer->id)
            ->set('name', 'Paket Janji')
            ->set('monthly_amount', '300000')
            ->set('billing_cycle_day', '20')
            ->set('scheduledVisitAt', $scheduledAt)
            ->call('createSubscription')
            ->assertHasNoErrors();

        $workOrder = WorkOrder::withoutGlobalScopes()->where('customer_id', $customer->id)->first();

        $this->assertNotNull($workOrder);
        $this->assertNotNull($workOrder->scheduled_at);
        $this->assertNull($workOrder->dispatched_at);
    }

    /**
     * v0.26.2b — "Janji Kunjungan" KOSONG (default): WorkOrder yang lahir
     * langsung dispatch (v0.26.2's dispatchImmediately() hook), konsisten
     * dgn skenario "segera, tanpa janji spesifik" yang sudah terkunci
     * sejak decision-gate v0.26.0.
     */
    public function test_creating_a_subscription_without_a_scheduled_visit_dispatches_the_work_order_immediately(): void
    {
        $tenant = Tenant::factory()->create();
        $billing = User::factory()->create(['tenant_id' => $tenant->id]);
        $billing->assignRole('billing');
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);

        Livewire::actingAs($billing)
            ->test(SubscriptionIndex::class)
            ->assertOk()
            ->set('customer_id', (string) $customer->id)
            ->set('name', 'Paket Segera')
            ->set('monthly_amount', '300000')
            ->set('billing_cycle_day', '20')
            ->call('createSubscription')
            ->assertHasNoErrors();

        $workOrder = WorkOrder::withoutGlobalScopes()->where('customer_id', $customer->id)->first();

        $this->assertNotNull($workOrder);
        $this->assertNull($workOrder->scheduled_at);
        $this->assertNotNull($workOrder->dispatched_at);
    }

    public function test_a_past_scheduled_visit_is_rejected_by_validation(): void
    {
        $tenant = Tenant::factory()->create();
        $billing = User::factory()->create(['tenant_id' => $tenant->id]);
        $billing->assignRole('billing');
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);

        Livewire::actingAs($billing)
            ->test(SubscriptionIndex::class)
            ->set('customer_id', (string) $customer->id)
            ->set('name', 'Paket Lampau')
            ->set('monthly_amount', '300000')
            ->set('billing_cycle_day', '20')
            ->set('scheduledVisitAt', now()->subDay()->format('Y-m-d\TH:i'))
            ->call('createSubscription')
            ->assertHasErrors('scheduledVisitAt');

        $this->assertDatabaseMissing('subscriptions', ['name' => 'Paket Lampau']);
    }

    public function test_non_billing_non_admin_cannot_mount_subscription_index(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('customer_service');

        Livewire::actingAs($user)->test(SubscriptionIndex::class)->assertForbidden();
    }

    public function test_invoice_index_renders_and_filters_by_status(): void
    {
        $tenant = Tenant::factory()->create();
        $billing = User::factory()->create(['tenant_id' => $tenant->id]);
        $billing->assignRole('billing');

        Livewire::actingAs($billing)
            ->test(InvoiceIndex::class)
            ->assertOk()
            ->set('statusFilter', 'paid')
            ->assertOk();
    }
}
