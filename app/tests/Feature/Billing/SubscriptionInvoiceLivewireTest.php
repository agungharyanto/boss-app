<?php

namespace Tests\Feature\Billing;

use App\Livewire\Billing\InvoiceIndex;
use App\Livewire\Billing\SubscriptionIndex;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
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
