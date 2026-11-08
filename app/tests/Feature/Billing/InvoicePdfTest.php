<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Models\CompanyProfile;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\InvoicePdfService;
use App\Services\Company\CompanyProfileService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * v0.13.5.2 — PDF invoice server-side (dompdf). NOL kirim WA.
 */
class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @return array{0: Invoice, 1: Tenant, 2: Customer}
     */
    private function invoice(array $overrides = [], string $customerName = 'Pelanggan Uji'): array
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'name' => $customerName]);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null]);
        $invoice = Invoice::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'reseller_id' => null,
            'invoice_number' => 'INV/TEST/2026/11/000001',
            'subtotal' => 100000,
            'tax_total' => 11000,
            'grand_total' => 111000,
            'status' => InvoiceStatus::Pending->value,
        ], $overrides));
        $invoice->lineItems()->create([
            'tenant_id' => $invoice->tenant_id,
            'description' => 'Langganan HomeFixed 10Mbps',
            'quantity' => 1,
            'unit_price' => 100000,
            'line_total' => 100000,
        ]);

        return [$invoice->fresh(), $tenant, $customer];
    }

    public function test_render_returns_a_pdf_of_reasonable_size(): void
    {
        [$invoice] = $this->invoice();
        $pdf = app(InvoicePdfService::class)->render($invoice);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    public function test_html_view_contains_company_name_and_invoice_number(): void
    {
        [$invoice, $tenant] = $this->invoice();
        $company = app(CompanyProfileService::class)->resolveFor($tenant->id, null);
        $company['footer_note'] = config('invoice.footer_note');

        $html = View::make('billing.invoice-pdf', ['invoice' => $invoice, 'company' => $company, 'logoDataUri' => null])->render();

        $this->assertStringContainsString('INV/TEST/2026/11/000001', $html);
        $this->assertStringContainsString($company['brand_name'] ?: $company['legal_name'] ?: $tenant->name, $html);
    }

    public function test_number_parity_between_print_and_pdf_views(): void
    {
        [$invoice] = $this->invoice();
        // Keduanya @include partial angka yang SAMA.
        $pdfHtml = View::make('billing.invoice-pdf', [
            'invoice' => $invoice, 'company' => ['brand_name' => 'X', 'legal_name' => 'X', 'address' => '', 'email' => '', 'cs' => '', 'footer_note' => ''], 'logoDataUri' => null,
        ])->render();
        $printHtml = View::make('billing.invoice-print-standard', [
            'invoice' => $invoice, 'company' => ['name' => 'X', 'address' => '', 'phone' => '', 'email' => '', 'footer_note' => ''], 'thermalWidth' => '80', 'autoPrint' => false,
        ])->render();

        foreach (['Rp 100.000', 'Rp 11.000', 'Rp 111.000', 'PPN (11%)'] as $needle) {
            $this->assertStringContainsString($needle, $pdfHtml, "PDF harus memuat {$needle}");
            $this->assertStringContainsString($needle, $printHtml, "Print harus memuat {$needle}");
        }
    }

    public function test_markup_and_indonesian_chars_in_customer_name_are_escaped_and_do_not_break_render(): void
    {
        [$invoice] = $this->invoice(customerName: 'Budi <script> & "Niño" Ríosé');
        $pdf = app(InvoicePdfService::class)->render($invoice);
        $this->assertStringStartsWith('%PDF', $pdf);

        $html = View::make('billing.invoice-pdf', [
            'invoice' => $invoice->fresh(), 'company' => ['brand_name' => 'X', 'legal_name' => 'X', 'address' => '', 'email' => '', 'cs' => '', 'footer_note' => ''], 'logoDataUri' => null,
        ])->render();
        $this->assertStringContainsString('Budi &lt;script&gt; &amp; &quot;Niño&quot; Ríosé', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_paid_invoice_shows_lunas_stamp_unpaid_does_not(): void
    {
        [$paid] = $this->invoice(['status' => InvoiceStatus::Paid->value, 'paid_at' => now()]);
        [$unpaid] = $this->invoice(['invoice_number' => 'INV/TEST/2026/11/000002']);

        $paidHtml = View::make('billing.invoice-pdf', ['invoice' => $paid, 'company' => ['brand_name' => 'X', 'legal_name' => 'X', 'address' => '', 'email' => '', 'cs' => '', 'footer_note' => ''], 'logoDataUri' => null])->render();
        $unpaidHtml = View::make('billing.invoice-pdf', ['invoice' => $unpaid, 'company' => ['brand_name' => 'X', 'legal_name' => 'X', 'address' => '', 'email' => '', 'cs' => '', 'footer_note' => ''], 'logoDataUri' => null])->render();

        $this->assertStringContainsString('LUNAS', $paidHtml);
        $this->assertStringNotContainsString('LUNAS', $unpaidHtml);
    }

    public function test_corrupt_or_missing_logo_does_not_error(): void
    {
        Storage::fake('local');
        [$invoice, $tenant] = $this->invoice();
        // Logo rusak (bukan gambar).
        Storage::disk('local')->put('company-logos/broken.bin', 'ini-bukan-gambar-sama-sekali');
        CompanyProfile::query()->create(['tenant_id' => $tenant->id, 'logo_path' => 'company-logos/broken.bin']);

        $pdf = app(InvoicePdfService::class)->render($invoice);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_store_writes_to_private_disk_at_correct_path_and_is_idempotent(): void
    {
        Storage::fake('local');
        [$invoice, $tenant] = $this->invoice();

        $path = app(InvoicePdfService::class)->store($invoice);
        $expected = "invoice-pdfs/{$tenant->id}/{$invoice->id}.pdf";

        $this->assertSame($expected, $path);
        Storage::disk('local')->assertExists($expected);

        // Idempoten: panggil lagi → tetap 1 file di direktori tenant itu.
        app(InvoicePdfService::class)->store($invoice);
        $this->assertCount(1, Storage::disk('local')->files("invoice-pdfs/{$tenant->id}"));
    }

    // ── Akses (route) ────────────────────────────────────────────────────

    public function test_authorized_user_downloads_pdf(): void
    {
        [$invoice, $tenant] = $this->invoice();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->givePermissionTo('invoices.view');

        $this->actingAs($user)->get(route('web.invoices.pdf', $invoice))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_user_without_permission_is_denied(): void
    {
        [$invoice, $tenant] = $this->invoice();
        $user = User::factory()->create(['tenant_id' => $tenant->id]); // tanpa permission/role

        $this->actingAs($user)->get(route('web.invoices.pdf', $invoice))->assertForbidden();
    }

    public function test_cross_tenant_invoice_is_not_found(): void
    {
        [$invoice] = $this->invoice();
        $otherTenant = Tenant::factory()->create();
        $other = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $other->givePermissionTo('invoices.view');

        // TenantScope pada route-model-binding → 404 untuk invoice tenant lain.
        $this->actingAs($other)->get(route('web.invoices.pdf', $invoice))->assertNotFound();
    }

    // ── Nol WA ───────────────────────────────────────────────────────────

    public function test_rendering_pdf_never_queues_a_whatsapp_job(): void
    {
        Http::fake();
        Queue::fake();
        [$invoice] = $this->invoice();

        app(InvoicePdfService::class)->render($invoice);
        app(InvoicePdfService::class)->store($invoice);

        Queue::assertNothingPushed();
    }
}
