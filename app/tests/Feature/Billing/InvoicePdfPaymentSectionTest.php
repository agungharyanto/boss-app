<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Billing\InvoicePdfService;
use App\Services\Company\CompanyBankAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * v0.13.5.4 — bagian "Cara Pembayaran" di invoice PDF.
 */
class InvoicePdfPaymentSectionTest extends TestCase
{
    use RefreshDatabase;

    private function company(): array
    {
        return ['brand_name' => 'X', 'legal_name' => 'X', 'address' => '', 'email' => '', 'cs' => '', 'footer_note' => ''];
    }

    private function payment(): array
    {
        return [
            'manual' => ['enabled' => true, 'accounts' => [
                ['bank_name' => 'BCA', 'account_number' => '1234567890', 'account_holder' => 'PT Uji', 'is_default' => true],
            ]],
            'gateway' => ['enabled' => false, 'link' => null],
            'agent' => ['enabled' => false, 'name' => null, 'contact' => null],
            'cs' => '08123',
            'has_any' => true,
        ];
    }

    private function invoice(string $status): Invoice
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null]);
        $invoice = Invoice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'subscription_id' => $subscription->id, 'reseller_id' => null, 'status' => $status]);
        $invoice->lineItems()->create(['tenant_id' => $tenant->id, 'description' => 'Langganan', 'quantity' => 1, 'unit_price' => 100000, 'line_total' => 100000]);

        return $invoice->fresh();
    }

    public function test_section_present_for_pending_invoice(): void
    {
        $html = View::make('billing.invoice-pdf', [
            'invoice' => $this->invoice(InvoiceStatus::Pending->value),
            'company' => $this->company(),
            'logoDataUri' => null,
            'payment' => $this->payment(),
        ])->render();

        $this->assertStringContainsString('Cara Pembayaran', $html);
        $this->assertStringContainsString('BCA 1234567890 a.n. PT Uji', $html);
        // v0.13.5.5.1 — watermark "BELUM LUNAS" (kuning) hidup berdampingan dengan
        // kotak Cara Pembayaran; opacity rendah → keduanya tetap terbaca.
        $this->assertStringContainsString('<div class="watermark wm-belum">BELUM LUNAS</div>', $html);
    }

    public function test_section_hidden_for_paid_invoice(): void
    {
        $html = View::make('billing.invoice-pdf', [
            'invoice' => $this->invoice(InvoiceStatus::Paid->value),
            'company' => $this->company(),
            'logoDataUri' => null,
            'payment' => $this->payment(),
        ])->render();

        $this->assertStringNotContainsString('Cara Pembayaran', $html);
        // v0.13.5.5.1 — invoice Paid: badge "Lunas" (Indonesia), TANPA watermark.
        $this->assertStringContainsString('>Lunas<', $html);
        $this->assertStringNotContainsString('<div class="watermark"', $html);
    }

    public function test_section_hidden_for_cancelled_invoice(): void
    {
        $html = View::make('billing.invoice-pdf', [
            'invoice' => $this->invoice(InvoiceStatus::Cancelled->value),
            'company' => $this->company(),
            'logoDataUri' => null,
            'payment' => $this->payment(),
        ])->render();

        $this->assertStringNotContainsString('Cara Pembayaran', $html);
        // v0.13.5.5.1 — invoice Cancelled: watermark "DIBATALKAN" (abu).
        $this->assertStringContainsString('<div class="watermark wm-batal">DIBATALKAN</div>', $html);
    }

    public function test_render_produces_pdf_and_never_calls_xendit(): void
    {
        Http::fake();
        $invoice = $this->invoice(InvoiceStatus::Pending->value);
        app(CompanyBankAccountService::class)->create($invoice->tenant_id, ['bank_name' => 'BCA', 'account_number' => '111', 'account_holder' => 'PT Uji']);

        $pdf = app(InvoicePdfService::class)->render($invoice);
        $this->assertStringStartsWith('%PDF', $pdf);
        Http::assertNothingSent();
    }
}
