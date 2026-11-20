<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\InvoicePdfService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * v0.13.5.5 — KUITANSI PEMBAYARAN (T1 render + T2 unduh). NOL kirim WA, NOL
 * Xendit. Konten di-assert lewat View (dompdf output dikompres, tak bisa
 * di-grep); PDF service di-assert %PDF + path.
 */
class InvoiceReceiptPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // Isolasi disk: storeReceipt() menulis ke disk 'local' NYATA; tanpa
        // fake, sisa file antar-run mencemari assertCount file.
        Storage::fake('local');
    }

    /**
     * @return array{0: Invoice, 1: Tenant, 2: Customer}
     */
    private function paidInvoice(array $overrides = []): array
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'name' => 'Pelanggan Kuitansi']);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null]);
        $invoice = Invoice::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'reseller_id' => null,
            'invoice_number' => 'INV/TEST/2026/11/000055',
            'subtotal' => 100000,
            'tax_total' => 0,
            'grand_total' => 100000,
            'status' => InvoiceStatus::Paid->value,
            'paid_at' => now(),
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

    private function admin(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('superadmin');

        return $user;
    }

    // ---- T1: render ----

    public function test_render_receipt_rejects_non_paid_invoice(): void
    {
        [$invoice] = $this->paidInvoice(['status' => InvoiceStatus::Pending->value, 'paid_at' => null]);

        $this->expectException(InvalidArgumentException::class);
        app(InvoicePdfService::class)->renderReceipt($invoice);
    }

    public function test_render_receipt_paid_produces_pdf(): void
    {
        [$invoice] = $this->paidInvoice();

        $pdf = app(InvoicePdfService::class)->renderReceipt($invoice);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    public function test_receipt_view_shows_kuitansi_lunas_method_and_hides_cara_pembayaran(): void
    {
        [$invoice] = $this->paidInvoice();

        $html = View::make('billing.invoice-pdf', [
            'invoice' => $invoice,
            'company' => ['brand_name' => 'TOKEN WIFI', 'legal_name' => 'TOKEN WIFI', 'address' => '', 'email' => '', 'cs' => '', 'footer_note' => ''],
            'logoDataUri' => null,
            'payment' => null,
            'receipt' => true,
            'paymentMethod' => 'Tidak tercatat',
        ])->render();

        $this->assertStringContainsString('KUITANSI PEMBAYARAN', $html);
        $this->assertStringContainsString('LUNAS', $html);
        $this->assertStringContainsString('INV/TEST/2026/11/000055', $html);
        $this->assertStringContainsString('Metode bayar', $html);
        $this->assertStringContainsString('Tidak tercatat', $html);
        $this->assertStringContainsString('Diterima dari', $html);
        $this->assertStringNotContainsString('Cara Pembayaran', $html);
        $this->assertStringNotContainsString('INVOICE</h1>', $html);
    }

    public function test_default_invoice_view_is_unchanged_when_receipt_not_passed(): void
    {
        [$invoice] = $this->paidInvoice();

        // Pemanggil lama tidak mengoper 'receipt' — harus tetap mode INVOICE.
        $html = View::make('billing.invoice-pdf', [
            'invoice' => $invoice,
            'company' => ['brand_name' => 'X', 'legal_name' => 'X', 'address' => '', 'email' => '', 'cs' => '', 'footer_note' => ''],
            'logoDataUri' => null,
            'payment' => null,
        ])->render();

        $this->assertStringContainsString('INVOICE', $html);
        $this->assertStringNotContainsString('KUITANSI PEMBAYARAN', $html);
        $this->assertStringNotContainsString('Metode bayar', $html);
    }

    // ---- T1: label metode bayar (invoice lama tanpa payments vs ada baris) ----

    public function test_method_label_is_untracked_when_no_payments_row(): void
    {
        [$invoice] = $this->paidInvoice();

        $label = $this->invokeLabel($invoice);

        $this->assertSame('Tidak tercatat', $label);
    }

    public function test_method_label_reads_gateway_channel_when_a_paid_payment_exists(): void
    {
        [$invoice] = $this->paidInvoice();

        Payment::create([
            'tenant_id' => $invoice->tenant_id,
            'invoice_id' => $invoice->id,
            'xendit_reference_id' => 'xnd-test-1',
            'channel_type' => 'BRI_VA',
            'amount' => $invoice->grand_total,
            'status' => PaymentStatus::Paid->value,
            'paid_at' => now(),
        ]);

        $label = $this->invokeLabel($invoice->fresh());

        $this->assertStringStartsWith('Gateway — ', $label);
    }

    public function test_method_label_shows_real_manual_method_when_recorded(): void
    {
        [$invoice] = $this->paidInvoice();

        Payment::create([
            'tenant_id' => $invoice->tenant_id,
            'invoice_id' => $invoice->id,
            'xendit_reference_id' => null,
            'channel_type' => null,
            'method' => PaymentMethod::ManualTransfer,
            'amount' => $invoice->grand_total,
            'status' => PaymentStatus::Paid->value,
            'paid_at' => now(),
        ]);

        $this->assertSame('Transfer Manual', $this->invokeLabel($invoice->fresh()));
    }

    private function invokeLabel(Invoice $invoice): string
    {
        $m = new ReflectionMethod(InvoicePdfService::class, 'receiptPaymentMethodLabel');
        $m->setAccessible(true);

        return $m->invoke(app(InvoicePdfService::class), $invoice);
    }

    // ---- T1: store ----

    public function test_store_receipt_writes_to_private_disk_at_receipt_path_and_is_idempotent(): void
    {
        [$invoice, $tenant] = $this->paidInvoice();

        $path = app(InvoicePdfService::class)->storeReceipt($invoice);
        $expected = "invoice-pdfs/{$tenant->id}/{$invoice->id}-receipt.pdf";

        $this->assertSame($expected, $path);
        Storage::disk('local')->assertExists($expected);

        $path2 = app(InvoicePdfService::class)->storeReceipt($invoice);
        $this->assertSame($expected, $path2);
        // Satu file kuitansi (idempoten), tidak menimpa file invoice biasa.
        $this->assertCount(1, Storage::disk('local')->files("invoice-pdfs/{$tenant->id}"));
    }

    // ---- T2: unduh (route + policy) ----

    public function test_download_receipt_paid_returns_pdf(): void
    {
        [$invoice, $tenant] = $this->paidInvoice();

        $res = $this->actingAs($this->admin($tenant))->get("/invoices/{$invoice->id}/receipt");

        $res->assertOk();
        $res->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('kuitansi-', (string) $res->headers->get('Content-Disposition'));
    }

    public function test_download_receipt_non_paid_is_404(): void
    {
        [$invoice, $tenant] = $this->paidInvoice(['status' => InvoiceStatus::Pending->value, 'paid_at' => null]);

        $this->actingAs($this->admin($tenant))
            ->get("/invoices/{$invoice->id}/receipt")
            ->assertNotFound();
    }

    public function test_download_receipt_requires_authentication(): void
    {
        [$invoice] = $this->paidInvoice();

        $this->get("/invoices/{$invoice->id}/receipt")->assertRedirect('/login');
    }
}
