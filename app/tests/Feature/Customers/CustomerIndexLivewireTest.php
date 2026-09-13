<?php

namespace Tests\Feature\Customers;

use App\Enums\InvoiceStatus;
use App\Livewire\Customers\CustomerIndex;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CustomerIndexLivewireTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'customers.view', 'guard_name' => 'web']));

        return $user;
    }

    public function test_search_by_name_is_case_insensitive(): void
    {
        $tenant = Tenant::factory()->create();
        Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Budi Santoso']);
        Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Ani Wijaya']);

        Livewire::actingAs($this->viewer($tenant))
            ->test(CustomerIndex::class)
            ->set('search', 'BUDI santoso')
            ->assertSee('Budi Santoso')
            ->assertDontSee('Ani Wijaya');
    }

    public function test_search_by_phone_number_is_case_insensitive_and_partial(): void
    {
        // phone_number is all-digits — this proves whereRaw(LOWER(...))
        // still does a partial LIKE match, not just casing.
        $tenant = Tenant::factory()->create();
        Customer::factory()->create(['tenant_id' => $tenant->id, 'phone_number' => '081234567890']);
        Customer::factory()->create(['tenant_id' => $tenant->id, 'phone_number' => '089988877766']);

        Livewire::actingAs($this->viewer($tenant))
            ->test(CustomerIndex::class)
            ->set('search', '81234567')
            ->assertSee('081234567890')
            ->assertDontSee('089988877766');
    }

    // ── v0.12.2 — kolom Jatuh Tempo + badge status (overdue/paid) ──

    public function test_due_date_column_shows_the_latest_invoice_due_date_and_status_badge(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Budi Santoso']);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id,
        ]);
        Invoice::factory()->create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'status' => InvoiceStatus::Overdue, 'due_date' => '2026-08-01',
        ]);

        Livewire::actingAs($this->viewer($tenant))
            ->test(CustomerIndex::class)
            ->assertSee('2026-08-01')
            ->assertSee(InvoiceStatus::Overdue->label());
    }

    public function test_due_date_column_uses_the_most_recent_invoice_when_several_exist(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id,
        ]);
        Invoice::factory()->create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'status' => InvoiceStatus::Paid,
            'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'due_date' => '2026-06-01',
        ]);
        Invoice::factory()->create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'status' => InvoiceStatus::Pending,
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'due_date' => '2026-09-01',
        ]);

        $component = Livewire::actingAs($this->viewer($tenant))->test(CustomerIndex::class);

        $component->assertSee('2026-09-01')->assertDontSee('2026-06-01');
    }

    public function test_customer_with_no_invoice_shows_a_dash_in_due_date_column(): void
    {
        $tenant = Tenant::factory()->create();
        Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Tanpa Invoice']);

        Livewire::actingAs($this->viewer($tenant))
            ->test(CustomerIndex::class)
            ->assertSee('Tanpa Invoice')
            ->assertSee('—');
    }
}
