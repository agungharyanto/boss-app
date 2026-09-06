<?php

namespace Tests\Feature\Customers;

use App\Enums\InvoiceStatus;
use App\Livewire\Customers\CustomerShow;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bagian B (v0.9.12) — section "Invoice" khusus pelanggan di halaman
 * Detail Pelanggan (referensi MixRadius "Invoice & Session").
 */
class CustomerShowInvoiceSectionTest extends TestCase
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

    private function invoiceFor(Customer $customer, string $number): Invoice
    {
        $sub = Subscription::factory()->create([
            'tenant_id' => $customer->tenant_id, 'customer_id' => $customer->id, 'reseller_id' => null,
        ]);
        $inv = Invoice::factory()->create([
            'tenant_id' => $customer->tenant_id, 'customer_id' => $customer->id,
            'subscription_id' => $sub->id, 'reseller_id' => null,
            'invoice_number' => $number, 'status' => InvoiceStatus::Paid,
            'subtotal' => 99000, 'tax_total' => 0, 'grand_total' => 99000,
        ]);
        $inv->lineItems()->create([
            'tenant_id' => $customer->tenant_id,
            'description' => 'Perpanjangan layanan — Paket Test',
            'quantity' => 1, 'unit_price' => 99000, 'line_total' => 99000,
        ]);

        return $inv;
    }

    public function test_customer_invoices_are_listed_only_for_that_customer(): void
    {
        $tenant = Tenant::factory()->create();
        $a = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'name' => 'Pelanggan A']);
        $b = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'name' => 'Pelanggan B']);

        $this->invoiceFor($a, 'INV/DIRECT/2026/09/000001');
        $this->invoiceFor($b, 'INV/DIRECT/2026/09/000099');

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerShow::class, ['customer' => $a])
            ->assertViewHas('customerInvoices', fn ($inv) => $inv->count() === 1 && $inv->first()->invoice_number === 'INV/DIRECT/2026/09/000001')
            ->assertSee('INV/DIRECT/2026/09/000001')
            ->assertDontSee('INV/DIRECT/2026/09/000099')
            ->assertSee('Perpanjangan layanan — Paket Test');
    }

    public function test_print_links_point_at_the_invoice_print_route(): void
    {
        $tenant = Tenant::factory()->create();
        $c = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $inv = $this->invoiceFor($c, 'INV/DIRECT/2026/09/000007');

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerShow::class, ['customer' => $c])
            ->assertSeeHtml("/invoices/{$inv->id}/print")
            ->assertSee('format=thermal')
            ->assertSee('Print Thermal 58mm')
            ->assertSee('Print Standar (A4)');
    }

    public function test_empty_state_when_customer_has_no_invoices(): void
    {
        $tenant = Tenant::factory()->create();
        $c = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerShow::class, ['customer' => $c])
            ->assertSee('Belum ada invoice');
    }
}
