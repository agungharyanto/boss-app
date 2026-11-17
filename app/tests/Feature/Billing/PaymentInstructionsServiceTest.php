<?php

namespace Tests\Feature\Billing;

use App\Models\CompanyProfile;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Referrer;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Billing\PaymentInstructionsService;
use App\Services\Company\CompanyBankAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * v0.13.5.4 — perakit "Cara Pembayaran" (render-only, TANPA Xendit).
 */
class PaymentInstructionsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(array $switches = [], ?int $referrerId = null): Invoice
    {
        $tenant = Tenant::factory()->create();
        CompanyProfile::create(array_merge([
            'tenant_id' => $tenant->id,
            'legal_name' => 'PT Uji',
            'cs_whatsapp' => '08123',
        ], $switches));
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'referred_by_referrer_id' => $referrerId,
        ]);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null]);

        return Invoice::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'reseller_id' => null,
        ]);
    }

    private function svc(): PaymentInstructionsService
    {
        return app(PaymentInstructionsService::class);
    }

    public function test_manual_lists_active_accounts_default_first(): void
    {
        $invoice = $this->invoice(['pay_manual_transfer_enabled' => true]);
        $bank = app(CompanyBankAccountService::class);
        $bank->create($invoice->tenant_id, ['bank_name' => 'BCA', 'account_number' => '111', 'account_holder' => 'PT Uji']);
        $second = $bank->create($invoice->tenant_id, ['bank_name' => 'Mandiri', 'account_number' => '222', 'account_holder' => 'PT Uji']);
        $bank->makeDefault($second);

        $data = $this->svc()->forInvoice($invoice);
        $this->assertTrue($data['manual']['enabled']);
        $this->assertCount(2, $data['manual']['accounts']);
        $this->assertSame('Mandiri', $data['manual']['accounts'][0]['bank_name']);
        $this->assertTrue($data['manual']['accounts'][0]['is_default']);
        $this->assertTrue($data['has_any']);
    }

    public function test_manual_no_accounts_has_any_false_but_cs_present(): void
    {
        $invoice = $this->invoice(['pay_manual_transfer_enabled' => true]);
        $data = $this->svc()->forInvoice($invoice);
        $this->assertFalse($data['has_any']);
        $this->assertSame('08123', $data['cs']);
    }

    public function test_gateway_uses_existing_payment_link_only_never_xendit(): void
    {
        Http::fake();
        $invoice = $this->invoice(['pay_manual_transfer_enabled' => false, 'pay_gateway_enabled' => true]);
        Payment::create([
            'tenant_id' => $invoice->tenant_id,
            'invoice_id' => $invoice->id,
            'channel_type' => 'XENDIT_INVOICE',
            'amount' => 100000,
            'status' => 'pending',
            'raw_response' => ['invoice_url' => 'https://checkout.example/abc'],
        ]);

        $before = Payment::count();
        $data = $this->svc()->forInvoice($invoice);

        $this->assertSame('https://checkout.example/abc', $data['gateway']['link']);
        $this->assertEquals($before, Payment::count(), 'tidak boleh membuat Payment baru saat render');
        Http::assertNothingSent();
    }

    public function test_gateway_without_existing_payment_yields_null_link(): void
    {
        $invoice = $this->invoice(['pay_manual_transfer_enabled' => false, 'pay_gateway_enabled' => true]);
        $data = $this->svc()->forInvoice($invoice);
        $this->assertNull($data['gateway']['link']);
        $this->assertEquals(0, Payment::count());
    }

    public function test_agent_shows_referrer_contact_when_present(): void
    {
        $referrer = Referrer::factory()->create(['name' => 'Budi Agen', 'phone' => '0877']);
        $invoice = $this->invoice(['pay_manual_transfer_enabled' => false, 'pay_agent_enabled' => true], $referrer->id);

        $data = $this->svc()->forInvoice($invoice);
        $this->assertSame('Budi Agen', $data['agent']['name']);
        $this->assertSame('0877', $data['agent']['contact']);
    }

    public function test_agent_without_referrer_is_null(): void
    {
        $invoice = $this->invoice(['pay_manual_transfer_enabled' => false, 'pay_agent_enabled' => true], null);
        $data = $this->svc()->forInvoice($invoice);
        $this->assertNull($data['agent']['name']);
        $this->assertFalse($data['has_any']);
    }

    public function test_no_company_profile_defaults_to_manual_on(): void
    {
        // invoice tanpa profile row.
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null]);
        $invoice = Invoice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'subscription_id' => $subscription->id, 'reseller_id' => null]);

        $data = $this->svc()->forInvoice($invoice);
        $this->assertTrue($data['manual']['enabled']);
    }

    public function test_caption_text_prefers_default_bank(): void
    {
        $invoice = $this->invoice(['pay_manual_transfer_enabled' => true]);
        app(CompanyBankAccountService::class)->create($invoice->tenant_id, ['bank_name' => 'BCA', 'account_number' => '111', 'account_holder' => 'PT Uji']);

        $text = $this->svc()->captionText($invoice);
        $this->assertStringContainsString('Transfer ke BCA 111 a.n. PT Uji', $text);
    }

    public function test_caption_text_empty_when_no_method_data(): void
    {
        $invoice = $this->invoice(['pay_manual_transfer_enabled' => true]);
        $this->assertSame('', $this->svc()->captionText($invoice));
    }
}
