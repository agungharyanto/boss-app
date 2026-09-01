<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * v0.9.5 — pematangan komisi otomatis lewat InvoiceService::markPaid().
 */
class CommissionMaturityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake(); // jangan benar-benar antri job WhatsApp

        $this->tenant = Tenant::factory()->create();
        $this->actingAs(User::factory()->create(['tenant_id' => $this->tenant->id]));
    }

    private function pendingInvoiceFor(Customer $customer)
    {
        $subscription = app(SubscriptionService::class)->create($customer, [
            'name' => 'Paket Test',
            'monthly_amount' => 100000,
            'billing_cycle_day' => now()->day,
            'started_at' => now()->subMonth()->toDateString(),
        ]);
        $invoice = app(InvoiceService::class)->generateNextForSubscription($subscription);

        return app(InvoiceService::class)->markPending($invoice);
    }

    private function ledgerFor(Customer $customer, ?float $amount, ?CommissionScheme $scheme = null, CommissionStatus $status = CommissionStatus::Pending): CommissionLedger
    {
        return CommissionLedger::factory()->create([
            'tenant_id' => $this->tenant->id,
            'referrer_id' => Referrer::factory()->create(['tenant_id' => $this->tenant->id])->id,
            'customer_id' => $customer->id,
            'amount' => $amount,
            'scheme' => $scheme?->value,
            'status' => $status,
        ]);
    }

    public function test_paying_an_invoice_matures_a_pending_ledger_with_amount_to_eligible(): void
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'reseller_id' => null]);
        $ledger = $this->ledgerFor($customer, 27500, CommissionScheme::Recurring);
        $invoice = $this->pendingInvoiceFor($customer);

        app(InvoiceService::class)->markPaid($invoice);

        $this->assertSame(CommissionStatus::Eligible, $ledger->fresh()->status);
        $this->assertStringContainsString($invoice->invoice_number, (string) $ledger->fresh()->notes);
    }

    public function test_limited_count_scheme_also_matures(): void
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'reseller_id' => null]);
        $ledger = $this->ledgerFor($customer, 40000, CommissionScheme::LimitedCount);
        $invoice = $this->pendingInvoiceFor($customer);

        app(InvoiceService::class)->markPaid($invoice);

        $this->assertSame(CommissionStatus::Eligible, $ledger->fresh()->status);
    }

    public function test_a_pending_ledger_without_amount_stays_pending(): void
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'reseller_id' => null]);
        $ledger = $this->ledgerFor($customer, null); // referrer diisi tanpa skema (v0.9.4 backward compat)
        $invoice = $this->pendingInvoiceFor($customer);

        app(InvoiceService::class)->markPaid($invoice);

        $this->assertSame(CommissionStatus::Pending, $ledger->fresh()->status);
    }

    public function test_a_non_pending_ledger_is_never_touched(): void
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'reseller_id' => null]);
        $approved = $this->ledgerFor($customer, 15000, CommissionScheme::Recurring, CommissionStatus::Approved);
        $rejected = $this->ledgerFor($customer, 15000, CommissionScheme::Recurring, CommissionStatus::Rejected);
        $invoice = $this->pendingInvoiceFor($customer);

        app(InvoiceService::class)->markPaid($invoice);

        $this->assertSame(CommissionStatus::Approved, $approved->fresh()->status);
        $this->assertSame(CommissionStatus::Rejected, $rejected->fresh()->status);
    }

    public function test_skipping_payment_leaves_the_ledger_pending(): void
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'reseller_id' => null]);
        $ledger = $this->ledgerFor($customer, 27500, CommissionScheme::Recurring);

        // Invoice dibuat tapi TIDAK dibayar — "skip = gugur, bukan tertunda".
        $this->pendingInvoiceFor($customer);

        $this->assertSame(CommissionStatus::Pending, $ledger->fresh()->status);
    }

    public function test_another_customers_ledger_is_not_affected(): void
    {
        $paying = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'reseller_id' => null]);
        $other = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'reseller_id' => null]);
        $otherLedger = $this->ledgerFor($other, 27500, CommissionScheme::Recurring);
        $invoice = $this->pendingInvoiceFor($paying);

        app(InvoiceService::class)->markPaid($invoice);

        $this->assertSame(CommissionStatus::Pending, $otherLedger->fresh()->status);
    }

    public function test_a_ledger_in_another_tenant_is_not_affected(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreignCustomer = Customer::factory()->create(['tenant_id' => $otherTenant->id, 'reseller_id' => null]);
        $foreignLedger = CommissionLedger::factory()->create([
            'tenant_id' => $otherTenant->id,
            'referrer_id' => Referrer::factory()->create(['tenant_id' => $otherTenant->id])->id,
            'customer_id' => $foreignCustomer->id,
            'amount' => 27500,
            'status' => CommissionStatus::Pending,
        ]);

        $payingCustomer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'reseller_id' => null]);
        $invoice = $this->pendingInvoiceFor($payingCustomer);
        app(InvoiceService::class)->markPaid($invoice);

        $this->assertSame(CommissionStatus::Pending, $foreignLedger->fresh()->status);
    }

    public function test_paying_an_invoice_for_a_customer_with_no_ledger_is_a_no_op(): void
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'reseller_id' => null]);
        $invoice = $this->pendingInvoiceFor($customer);

        app(InvoiceService::class)->markPaid($invoice);

        $this->assertSame(0, CommissionLedger::withoutGlobalScopes()->count());
    }
}
