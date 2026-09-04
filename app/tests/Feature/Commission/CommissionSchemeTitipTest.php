<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Enums\NetworkProfileGroupType;
use App\Models\BandwidthProfile;
use App\Models\CommissionLedger;
use App\Models\CommissionRate;
use App\Models\Customer;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * v0.9.6 — `CommissionScheme::Titip` + interaksinya dengan
 * CommissionLedgerMaturityService (jalur invoice-lunas).
 */
class CommissionSchemeTitipTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Referrer $referrer;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        $this->tenant = Tenant::factory()->create();
        $this->actingAs(User::factory()->create(['tenant_id' => $this->tenant->id]));
        $this->referrer = Referrer::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function rate(?float $recurring, ?float $titip): CommissionRate
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

        return CommissionRate::factory()->create([
            'ppp_package_id' => $package->id,
            'recurring_amount' => $recurring,
            'titip_amount' => $titip,
            'is_active' => true,
        ]);
    }

    public function test_amount_for_scheme_resolves_titip(): void
    {
        $rate = $this->rate(recurring: 5000, titip: 3000);

        $this->assertSame(3000.0, $rate->amountForScheme(CommissionScheme::Titip->value));
        $this->assertSame(3000.0, $rate->titipAmount());
    }

    public function test_scheme_options_never_offers_titip(): void
    {
        $rate = $this->rate(recurring: 5000, titip: 3000);

        $this->assertArrayNotHasKey(CommissionScheme::Titip->value, $rate->schemeOptions());
        $this->assertArrayHasKey(CommissionScheme::Recurring->value, $rate->schemeOptions());
    }

    public function test_maturity_service_ignores_a_titip_only_ledger(): void
    {
        $rate = $this->rate(recurring: 5000, titip: 3000);

        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $rate->ppp_package_id,
            'referred_by_referrer_id' => $this->referrer->id,
        ]);

        // Satu-satunya baris = titip (dari portal). Tidak ada template atribusi.
        CommissionLedger::factory()->create([
            'tenant_id' => $this->tenant->id,
            'referrer_id' => $this->referrer->id,
            'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Titip->value,
            'status' => CommissionStatus::Eligible,
            'amount' => 3000,
            'payment_period' => now()->startOfMonth()->toDateString(),
        ]);

        $this->payInvoiceFor($customer);

        // Tetap 1 baris (yang titip), tidak ada baris recurring yang lahir.
        $this->assertSame(1, CommissionLedger::withoutGlobalScopes()->where('customer_id', $customer->id)->count());
        $this->assertDatabaseMissing('commission_ledger', [
            'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Recurring->value,
        ]);
    }

    public function test_a_titip_row_does_not_shadow_the_recurring_template(): void
    {
        $rate = $this->rate(recurring: 5000, titip: 3000);

        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $rate->ppp_package_id,
            'referred_by_referrer_id' => $this->referrer->id,
        ]);

        // Template recurring (v0.9.4) + baris titip yang dibuat lebih dulu.
        $template = CommissionLedger::factory()->create([
            'tenant_id' => $this->tenant->id,
            'referrer_id' => $this->referrer->id,
            'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Recurring->value,
            'status' => CommissionStatus::Pending,
            'amount' => null,
        ]);
        CommissionLedger::factory()->create([
            'tenant_id' => $this->tenant->id,
            'referrer_id' => $this->referrer->id,
            'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Titip->value,
            'status' => CommissionStatus::Eligible,
            'amount' => 3000,
            'payment_period' => now()->startOfMonth()->toDateString(),
        ]);

        $this->payInvoiceFor($customer);

        $template->refresh();
        $this->assertSame(CommissionStatus::Eligible, $template->status);
        $this->assertSame('5000.00', $template->amount);
    }

    private function payInvoiceFor(Customer $customer): void
    {
        $subscription = app(SubscriptionService::class)->create($customer, [
            'name' => 'Paket Test',
            'monthly_amount' => 100000,
            'billing_cycle_day' => now()->day,
            'started_at' => now()->subMonth()->toDateString(),
        ]);
        $invoice = app(InvoiceService::class)->generateNextForSubscription($subscription);
        $invoice = app(InvoiceService::class)->markPending($invoice);
        app(InvoiceService::class)->markPaid($invoice);
    }
}
