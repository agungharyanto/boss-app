<?php

namespace Tests\Feature\Billing;

use App\Enums\WhatsappEventType;
use App\Models\CompanyProfile;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Company\CompanyBankAccountService;
use App\Services\Whatsapp\WhatsappGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * v0.13.5.4 — variabel {payment_instructions} di caption invoice_issued.
 */
class InvoiceIssuedCaptionPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(): Invoice
    {
        $tenant = Tenant::factory()->create();
        CompanyProfile::create(['tenant_id' => $tenant->id, 'legal_name' => 'PT Uji', 'pay_manual_transfer_enabled' => true]);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null]);

        return Invoice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'subscription_id' => $subscription->id, 'reseller_id' => null]);
    }

    private function buildVars(WhatsappEventType $event, Invoice $invoice): array
    {
        $svc = app(WhatsappGatewayService::class);
        $m = new ReflectionMethod($svc, 'buildVariables');
        $m->setAccessible(true);

        return $m->invoke($svc, $event, $invoice->customer, $invoice);
    }

    public function test_invoice_issued_caption_includes_payment_instructions(): void
    {
        $invoice = $this->invoice();
        app(CompanyBankAccountService::class)->create($invoice->tenant_id, ['bank_name' => 'BCA', 'account_number' => '111', 'account_holder' => 'PT Uji']);

        $vars = $this->buildVars(WhatsappEventType::InvoiceIssued, $invoice);
        $this->assertArrayHasKey('payment_instructions', $vars);
        $this->assertStringContainsString('Transfer ke BCA 111 a.n. PT Uji', $vars['payment_instructions']);
    }

    public function test_other_event_has_null_payment_instructions(): void
    {
        $invoice = $this->invoice();
        $vars = $this->buildVars(WhatsappEventType::PaymentReceived, $invoice);
        $this->assertNull($vars['payment_instructions']);
    }
}
