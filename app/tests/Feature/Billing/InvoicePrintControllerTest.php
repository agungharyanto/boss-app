<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint "perpanjang-invoice-asli-cetak" — cetak Invoice browser-print,
 * DUA tipe (standard A4 vs thermal 58/80mm): DATA sama, FORMAT beda.
 */
class InvoicePrintControllerTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(): Invoice
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Budi Santoso', 'reseller_id' => null]);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null,
        ]);
        $invoice = Invoice::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'reseller_id' => null,
            'invoice_number' => 'INV/DIRECT/2026/09/000042',
            'status' => InvoiceStatus::Paid,
            'subtotal' => 100000,
            'tax_total' => 0,
            'grand_total' => 100000,
            'paid_at' => now(),
        ]);
        $invoice->lineItems()->create([
            'tenant_id' => $tenant->id,
            'description' => 'Perpanjangan layanan — HomeFixed 10Mbps (September 2026)',
            'quantity' => 1,
            'unit_price' => 100000,
            'line_total' => 100000,
        ]);

        return $invoice;
    }

    private function admin(Tenant $tenant): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('superadmin');

        return $user;
    }

    public function test_standard_print_renders_a4_layout_with_the_invoice_data(): void
    {
        $invoice = $this->invoice();

        $res = $this->actingAs($this->admin(Tenant::find($invoice->tenant_id)))
            ->get("/invoices/{$invoice->id}/print");

        $res->assertOk();
        $res->assertSee('INV/DIRECT/2026/09/000042');
        $res->assertSee('Budi Santoso');
        $res->assertSee('Perpanjangan layanan — HomeFixed 10Mbps (September 2026)', false);
        $res->assertSee('@page { size: A4', false);
        $res->assertSee('LUNAS');
        $res->assertDontSee('58mm auto', false);
    }

    public function test_thermal_print_renders_narrow_layout_with_the_same_data(): void
    {
        $invoice = $this->invoice();

        $res = $this->actingAs($this->admin(Tenant::find($invoice->tenant_id)))
            ->get("/invoices/{$invoice->id}/print?format=thermal&width=58");

        $res->assertOk();
        $res->assertSee('INV/DIRECT/2026/09/000042');
        $res->assertSee('@page { size: 58mm auto', false);
        $res->assertSee('L U N A S');
        $res->assertDontSee('size: A4', false);
    }

    public function test_thermal_defaults_to_80mm_when_width_not_given(): void
    {
        $invoice = $this->invoice();

        $res = $this->actingAs($this->admin(Tenant::find($invoice->tenant_id)))
            ->get("/invoices/{$invoice->id}/print?format=thermal");

        $res->assertOk();
        $res->assertSee('@page { size: 80mm auto', false);
    }

    public function test_autoprint_can_be_disabled(): void
    {
        $invoice = $this->invoice();
        $admin = $this->admin(Tenant::find($invoice->tenant_id));

        $this->actingAs($admin)->get("/invoices/{$invoice->id}/print")
            ->assertSee('window.print()', false);

        $this->actingAs($admin)->get("/invoices/{$invoice->id}/print?autoprint=0")
            ->assertDontSee('setTimeout(function () { window.print(); }', false);
    }

    public function test_a_user_without_invoice_access_is_forbidden(): void
    {
        $invoice = $this->invoice();
        $this->seed(RolesAndPermissionsSeeder::class);
        $outsider = User::factory()->create(['tenant_id' => $invoice->tenant_id]);
        $outsider->assignRole('teknisi');

        $this->actingAs($outsider)->get("/invoices/{$invoice->id}/print")->assertForbidden();
    }
}
