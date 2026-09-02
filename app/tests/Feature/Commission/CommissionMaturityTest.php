<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Enums\NetworkProfileGroupType;
use App\Models\BandwidthProfile;
use App\Models\CommissionLedger;
use App\Models\CommissionRate;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\Referrer;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CommissionLedgerMaturityService;
use App\Services\InvoiceService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * v0.9.5 (redesain append-per-invoice) — komisi diperoleh PER invoice lunas.
 */
class CommissionMaturityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Referrer $referrer;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake(); // jangan benar-benar antri job WhatsApp

        $this->tenant = Tenant::factory()->create();
        $this->actingAs(User::factory()->create(['tenant_id' => $this->tenant->id]));
        $this->referrer = Referrer::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function package(?float $recurring, ?float $limitedAmount = null, ?int $limitedTimes = null): PppPackage
    {
        $group = NetworkProfileGroup::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => NetworkProfileGroupType::Ppp,
        ]);

        $package = PppPackage::factory()->create([
            'tenant_id' => $this->tenant->id,
            'network_profile_group_id' => $group->id,
            'bandwidth_profile_id' => BandwidthProfile::factory()->create(['tenant_id' => $this->tenant->id])->id,
        ]);

        CommissionRate::factory()->create([
            'ppp_package_id' => $package->id,
            'recurring_amount' => $recurring,
            'limited_count_amount' => $limitedAmount,
            'limited_count_times' => $limitedTimes,
        ]);

        return $package;
    }

    /**
     * Pelanggan + referral + baris "template" v0.9.4 (Pending, invoice_id NULL).
     */
    private function customerWithReferral(PppPackage $package, ?CommissionScheme $scheme, ?float $templateAmount = null): Customer
    {
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $package->id,
            'referred_by_referrer_id' => $this->referrer->id,
        ]);

        CommissionLedger::factory()->create([
            'tenant_id' => $this->tenant->id,
            'referrer_id' => $this->referrer->id,
            'customer_id' => $customer->id,
            'invoice_id' => null,
            'amount' => $templateAmount,
            'scheme' => $scheme?->value,
            'status' => CommissionStatus::Pending,
        ]);

        return $customer;
    }

    private function subscriptionFor(Customer $customer): Subscription
    {
        return app(SubscriptionService::class)->create($customer, [
            'name' => 'Paket Test',
            'monthly_amount' => 100000,
            'billing_cycle_day' => 1,
            'started_at' => now()->subMonths(6)->startOfMonth()->toDateString(),
        ]);
    }

    private function payNextInvoice(Subscription $subscription): Invoice
    {
        $invoice = app(InvoiceService::class)->generateNextForSubscription($subscription);
        app(InvoiceService::class)->markPending($invoice->fresh());

        return app(InvoiceService::class)->markPaid($invoice->fresh());
    }

    private function ledgerCount(Customer $customer): int
    {
        return CommissionLedger::withoutGlobalScopes()->where('customer_id', $customer->id)->count();
    }

    public function test_first_paid_invoice_matures_the_template_row_in_place(): void
    {
        $package = $this->package(recurring: 27500);
        $customer = $this->customerWithReferral($package, CommissionScheme::Recurring, templateAmount: 27500);
        $subscription = $this->subscriptionFor($customer);

        $invoice = $this->payNextInvoice($subscription);

        $this->assertSame(1, $this->ledgerCount($customer), 'baris template dimatangkan di tempat, bukan baris baru');
        $row = CommissionLedger::withoutGlobalScopes()->where('customer_id', $customer->id)->first();
        $this->assertSame(CommissionStatus::Eligible, $row->status);
        $this->assertSame($invoice->id, $row->invoice_id);
        $this->assertSame('27500.00', $row->amount);
    }

    public function test_amount_comes_from_the_current_rate_not_the_stale_template(): void
    {
        $package = $this->package(recurring: 30000);
        // template dibuat dengan amount lama 999 — harus di-override oleh rate
        $customer = $this->customerWithReferral($package, CommissionScheme::Recurring, templateAmount: 999);
        $subscription = $this->subscriptionFor($customer);

        $this->payNextInvoice($subscription);

        $row = CommissionLedger::withoutGlobalScopes()->where('customer_id', $customer->id)->first();
        $this->assertSame('30000.00', $row->amount);
    }

    public function test_recurring_appends_a_new_eligible_row_per_subsequent_paid_invoice(): void
    {
        $package = $this->package(recurring: 27500);
        $customer = $this->customerWithReferral($package, CommissionScheme::Recurring, templateAmount: 27500);
        $subscription = $this->subscriptionFor($customer);

        $i1 = $this->payNextInvoice($subscription);
        $i2 = $this->payNextInvoice($subscription);
        $i3 = $this->payNextInvoice($subscription);

        $rows = CommissionLedger::withoutGlobalScopes()->where('customer_id', $customer->id)->orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertEqualsCanonicalizing(
            [$i1->id, $i2->id, $i3->id],
            $rows->pluck('invoice_id')->all(),
        );
        $rows->each(fn ($r) => $this->assertSame(CommissionStatus::Eligible, $r->status));
        $rows->each(fn ($r) => $this->assertSame('27500.00', $r->amount));
    }

    public function test_limited_count_stops_generating_after_the_cap(): void
    {
        $package = $this->package(recurring: null, limitedAmount: 40000, limitedTimes: 2);
        $customer = $this->customerWithReferral($package, CommissionScheme::LimitedCount, templateAmount: 40000);
        $subscription = $this->subscriptionFor($customer);

        $this->payNextInvoice($subscription); // #1 — matures template
        $this->payNextInvoice($subscription); // #2 — appends
        $this->payNextInvoice($subscription); // #3 — cap reached, nothing
        $this->payNextInvoice($subscription); // #4 — still nothing

        $rows = CommissionLedger::withoutGlobalScopes()->where('customer_id', $customer->id)->get();
        $this->assertCount(2, $rows, 'limited_count(2) menghasilkan tepat 2 baris komisi');
        $rows->each(fn ($r) => $this->assertSame(CommissionStatus::Eligible, $r->status));
    }

    public function test_a_template_row_without_scheme_never_generates_commission(): void
    {
        $package = $this->package(recurring: 27500);
        $customer = $this->customerWithReferral($package, scheme: null); // referrer diisi tanpa skema
        $subscription = $this->subscriptionFor($customer);

        $this->payNextInvoice($subscription);
        $this->payNextInvoice($subscription);

        $row = CommissionLedger::withoutGlobalScopes()->where('customer_id', $customer->id)->first();
        $this->assertSame(1, $this->ledgerCount($customer));
        $this->assertSame(CommissionStatus::Pending, $row->status);
        $this->assertNull($row->invoice_id);
    }

    public function test_skipping_payment_generates_no_commission_row(): void
    {
        $package = $this->package(recurring: 27500);
        $customer = $this->customerWithReferral($package, CommissionScheme::Recurring, templateAmount: 27500);
        $subscription = $this->subscriptionFor($customer);

        // Invoice dibuat & di-issue tapi TIDAK dibayar.
        $invoice = app(InvoiceService::class)->generateNextForSubscription($subscription);
        app(InvoiceService::class)->markPending($invoice->fresh());

        $row = CommissionLedger::withoutGlobalScopes()->where('customer_id', $customer->id)->first();
        $this->assertSame(CommissionStatus::Pending, $row->status);
        $this->assertNull($row->invoice_id);
    }

    public function test_the_same_invoice_cannot_generate_two_commission_rows(): void
    {
        $package = $this->package(recurring: 27500);
        $customer = $this->customerWithReferral($package, CommissionScheme::Recurring, templateAmount: 27500);
        $subscription = $this->subscriptionFor($customer);

        $invoice = app(InvoiceService::class)->generateNextForSubscription($subscription);
        app(InvoiceService::class)->markPending($invoice->fresh());
        app(InvoiceService::class)->markPaid($invoice->fresh());

        // panggil manual sekali lagi — guard idempotensi
        app(CommissionLedgerMaturityService::class)->matureForPaidInvoice($invoice->fresh());

        $this->assertSame(1, $this->ledgerCount($customer));
    }

    public function test_a_customer_with_no_referral_generates_nothing(): void
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'reseller_id' => null]);
        $subscription = $this->subscriptionFor($customer);

        $this->payNextInvoice($subscription);

        $this->assertSame(0, CommissionLedger::withoutGlobalScopes()->count());
    }

    public function test_another_customers_paid_invoice_does_not_touch_this_customers_template(): void
    {
        $package = $this->package(recurring: 27500);
        $other = $this->customerWithReferral($package, CommissionScheme::Recurring, templateAmount: 27500);

        $paying = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'reseller_id' => null]);
        $this->payNextInvoice($this->subscriptionFor($paying));

        $row = CommissionLedger::withoutGlobalScopes()->where('customer_id', $other->id)->first();
        $this->assertSame(CommissionStatus::Pending, $row->status);
    }

    public function test_a_template_in_another_tenant_is_not_affected(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreignCustomer = Customer::factory()->create(['tenant_id' => $otherTenant->id, 'reseller_id' => null]);
        $foreignReferrer = Referrer::factory()->create(['tenant_id' => $otherTenant->id]);
        $foreignLedger = CommissionLedger::factory()->create([
            'tenant_id' => $otherTenant->id,
            'referrer_id' => $foreignReferrer->id,
            'customer_id' => $foreignCustomer->id,
            'amount' => 27500,
            'scheme' => CommissionScheme::Recurring->value,
            'status' => CommissionStatus::Pending,
        ]);

        $package = $this->package(recurring: 27500);
        $paying = $this->customerWithReferral($package, CommissionScheme::Recurring, templateAmount: 27500);
        $this->payNextInvoice($this->subscriptionFor($paying));

        $this->assertSame(CommissionStatus::Pending, $foreignLedger->fresh()->status);
    }
}
