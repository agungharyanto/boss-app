<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Enums\InvoiceStatus;
use App\Enums\NetworkProfileGroupType;
use App\Enums\ReferrerType;
use App\Enums\SubscriptionStatus;
use App\Enums\TaxBurden;
use App\Enums\TaxComponentType;
use App\Enums\TitipDepositStatus;
use App\Models\BandwidthProfile;
use App\Models\CommissionLedger;
use App\Models\CommissionRate;
use App\Models\Customer;
use App\Models\CustomerTimelineEntry;
use App\Models\Invoice;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\Referrer;
use App\Models\ResellerTaxPolicy;
use App\Models\Subscription;
use App\Models\TaxComponent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\RenewalInvoiceService;
use App\Services\Commission\SubscriptionRenewalService;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SubscriptionRenewalServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private SubscriptionRenewalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->service = app(SubscriptionRenewalService::class);
    }

    private function package(?float $titip, string $name = 'Paket', float $sellPrice = 150000): PppPackage
    {
        $group = NetworkProfileGroup::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => NetworkProfileGroupType::Ppp,
        ]);

        $package = PppPackage::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'is_active' => true,
            'sell_price' => $sellPrice,
            'network_profile_group_id' => $group->id,
            'bandwidth_profile_id' => BandwidthProfile::factory()->create(['tenant_id' => $this->tenant->id])->id,
        ]);

        CommissionRate::factory()->create([
            'ppp_package_id' => $package->id,
            'recurring_amount' => 5000,
            'titip_amount' => $titip,
            'is_active' => true,
        ]);

        return $package;
    }

    private function actingUser(): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_renewal_by_a_user_with_no_linked_referrer_records_a_paid_period_row_without_a_commission(): void
    {
        $user = $this->actingUser();
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $this->package(3000)->id,
        ]);

        $result = $this->service->renew($user, $customer, null);

        $this->assertFalse($result['commission_created']);
        $this->assertNotNull($result['commission_skipped_reason']);
        $this->assertSame(1, $result['rows_created']);

        $row = CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->where('scheme', CommissionScheme::Titip->value)->sole();
        $this->assertNull($row->amount);
        $this->assertNull($row->referrer_id);
        $this->assertSame(TitipDepositStatus::SudahSetor, $row->deposit_status);

        $this->assertDatabaseHas('customer_timeline_entries', [
            'customer_id' => $customer->id,
            'event_type' => 'subscription_renewed',
        ]);
    }

    public function test_a_sales_referrer_without_a_titip_rate_on_the_package_records_a_null_amount_row(): void
    {
        $user = $this->actingUser();
        $referrer = Referrer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'type' => ReferrerType::Sales,
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $this->package(null)->id, // no titip_amount
        ]);

        $result = $this->service->renew($user, $customer, null);

        $this->assertFalse($result['commission_created']);
        $row = CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->where('scheme', CommissionScheme::Titip->value)->sole();
        $this->assertNull($row->amount);
        $this->assertSame($referrer->id, $row->referrer_id);
    }

    public function test_multi_month_creates_one_row_per_period_and_a_single_timeline_entry(): void
    {
        $user = $this->actingUser();
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'customers.view', 'guard_name' => 'web']));
        $referrer = Referrer::factory()->create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'type' => ReferrerType::Sales, 'is_active' => true,
        ]);
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id, 'reseller_id' => null,
            'ppp_package_id' => $this->package(3000, 'Paket', 200000)->id,
        ]);

        $start = Carbon::now()->startOfMonth();
        $result = $this->service->renew($user, $customer, null, 3, $start->copy());

        $this->assertSame(3, $result['rows_created']);
        $this->assertSame(3, $result['months']);
        $this->assertSame(9000.0, $result['commission_total']); // 3000 x 3
        $this->assertSame(3000.0, $result['commission_amount']);

        $rows = CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->where('scheme', CommissionScheme::Titip->value)
            ->orderBy('payment_period')->get();
        $this->assertCount(3, $rows);
        $rows->each(fn ($r) => $this->assertSame('200000.00', $r->gross_amount));
        $rows->each(fn ($r) => $this->assertSame('3000.00', $r->amount));

        $expectedPeriods = collect([0, 1, 2])
            ->map(fn ($i) => $start->copy()->addMonths($i)->toDateString())
            ->all();
        $this->assertSame(
            $expectedPeriods,
            $rows->pluck('payment_period')->map(fn ($p) => $p->toDateString())->all()
        );

        $this->assertSame(1, CustomerTimelineEntry::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->where('event_type', 'subscription_renewed')->count());
    }

    public function test_multi_month_is_rejected_for_a_non_admin_actor(): void
    {
        $user = $this->actingUser(); // no permissions, no admin access
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id, 'reseller_id' => null,
            'ppp_package_id' => $this->package(3000)->id,
        ]);

        try {
            $this->service->renew($user, $customer, null, 3);
            $this->fail('Expected multi-month renewal by a non-admin to be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('hanya untuk admin', $e->getMessage());
        }

        $this->assertSame(0, CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->count());
    }

    public function test_multi_month_with_a_single_conflicting_period_rejects_the_whole_range(): void
    {
        $user = $this->actingUser();
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'customers.view', 'guard_name' => 'web']));
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id, 'reseller_id' => null,
            'ppp_package_id' => $this->package(3000)->id,
        ]);

        $start = Carbon::now()->startOfMonth();
        $conflict = $start->copy()->addMonth();
        $ref = Referrer::factory()->create(['tenant_id' => $this->tenant->id]);
        CommissionLedger::factory()->create([
            'tenant_id' => $this->tenant->id, 'referrer_id' => $ref->id, 'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Titip->value, 'status' => CommissionStatus::Eligible,
            'payment_period' => $conflict->toDateString(),
        ]);

        try {
            $this->service->renew($user, $customer, null, 3, $start->copy());
            $this->fail('Expected the conflicting range to be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($conflict->translatedFormat('F Y'), $e->getMessage());
        }

        // Hanya baris konflik yang ada.
        $this->assertSame(1, CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->where('scheme', CommissionScheme::Titip->value)->count());
    }

    public function test_changing_the_package_updates_only_ppp_package_id(): void
    {
        $user = $this->actingUser();
        $from = $this->package(3000, 'Lama');
        $to = $this->package(4000, 'Baru');
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $from->id,
            'name' => 'Tetap',
            'phone_number' => '081200000000',
        ]);

        $originalName = $customer->name;
        $originalPhone = $customer->phone_number;

        $result = $this->service->renew($user, $customer, $to->id);

        $customer->refresh();
        $this->assertSame($to->id, $customer->ppp_package_id);
        $this->assertSame($originalName, $customer->name);
        $this->assertSame($originalPhone, $customer->phone_number);
        $this->assertTrue($result['package_changed']);
        $this->assertSame('Baru', $result['package_to']);
    }

    public function test_a_package_from_another_tenant_is_rejected(): void
    {
        $user = $this->actingUser();
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $this->package(3000)->id,
        ]);
        $foreignTenant = Tenant::factory()->create();
        $foreignPackage = PppPackage::factory()->create([
            'tenant_id' => $foreignTenant->id,
            'is_active' => true,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service->renew($user, $customer, $foreignPackage->id);
    }

    public function test_titip_row_snapshots_gross_amount_from_the_current_package_sell_price(): void
    {
        $user = $this->actingUser();
        Referrer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'type' => ReferrerType::Sales,
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $this->package(3000, 'Paket', 175000)->id,
        ]);

        $this->service->renew($user, $customer, null);

        $row = CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)
            ->where('scheme', CommissionScheme::Titip->value)
            ->sole();

        $this->assertSame('175000.00', $row->gross_amount);
        $this->assertSame('3000.00', $row->amount);
        $this->assertSame(TitipDepositStatus::BelumSetor, $row->deposit_status);
    }

    public function test_gross_amount_uses_the_new_package_sell_price_when_the_package_is_changed(): void
    {
        $user = $this->actingUser();
        Referrer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'type' => ReferrerType::Freelance,
            'is_active' => true,
        ]);
        $from = $this->package(3000, 'Lama', 100000);
        $to = $this->package(4000, 'Baru', 250000);
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $from->id,
        ]);

        $result = $this->service->renew($user, $customer, $to->id);

        $row = CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)
            ->where('scheme', CommissionScheme::Titip->value)
            ->sole();

        $this->assertSame('250000.00', $row->gross_amount);
        $this->assertSame('4000.00', $row->amount);
        $this->assertSame(250000.0, $result['commission_gross_amount']);
    }

    public function test_renew_is_hard_blocked_when_a_titip_row_already_exists_for_the_month(): void
    {
        $user = $this->actingUser();
        Referrer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'type' => ReferrerType::Sales,
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $this->package(3000)->id,
        ]);

        // First renewal succeeds.
        $this->service->renew($user, $customer, null);
        $this->assertSame(1, CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->where('scheme', CommissionScheme::Titip->value)->count());

        // Second — hard block, regardless of actor.
        try {
            $this->service->renew($user, $customer, null);
            $this->fail('Expected the second renewal to be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('sudah tercatat bayar', $e->getMessage());
        }

        $this->assertSame(1, CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->where('scheme', CommissionScheme::Titip->value)->count());
        // No extra timeline entry from the rejected attempt.
        $this->assertSame(1, CustomerTimelineEntry::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->where('event_type', 'subscription_renewed')->count());
    }

    public function test_hard_block_applies_even_to_an_actor_with_no_linked_referrer(): void
    {
        $user = $this->actingUser(); // no linked referrer
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $this->package(3000)->id,
        ]);

        $ref = Referrer::factory()->create(['tenant_id' => $this->tenant->id]);
        CommissionLedger::factory()->create([
            'tenant_id' => $this->tenant->id,
            'referrer_id' => $ref->id,
            'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Titip->value,
            'status' => CommissionStatus::Eligible,
            'payment_period' => now()->startOfMonth()->toDateString(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service->renew($user, $customer, null);
    }

    public function test_the_service_has_no_network_gateway_dependency(): void
    {
        $ctor = (new \ReflectionClass(SubscriptionRenewalService::class))->getConstructor();

        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : '';
            $this->assertStringNotContainsString('App\\Services\\Network', $name);
            $this->assertStringNotContainsString('RouterOs', $name);
        }
    }

    // ── Sprint "perpanjang-invoice-asli-cetak" — Invoice ASLI + maturity ──

    public function test_renew_creates_a_real_paid_invoice_and_matures_sales_commission_without_new_logic(): void
    {
        $user = $this->actingUser(); // admin, NOT a Sales referrer -> no Titip commission
        $package = $this->package(3000, 'Paket', 99000);

        $officialReferrer = Referrer::factory()->create([
            'tenant_id' => $this->tenant->id, 'type' => ReferrerType::Sales, 'is_active' => true,
        ]);
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id, 'reseller_id' => null,
            'ppp_package_id' => $package->id,
            'referred_by_referrer_id' => $officialReferrer->id,
        ]);
        // v0.9.4 template row: recurring scheme, Pending, no invoice yet.
        $template = CommissionLedger::factory()->create([
            'tenant_id' => $this->tenant->id,
            'referrer_id' => $officialReferrer->id,
            'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Recurring->value,
            'status' => CommissionStatus::Pending,
            'amount' => null,
            'invoice_id' => null,
        ]);

        $result = $this->service->renew($user, $customer, null);

        // Invoice created + paid.
        $this->assertSame(1, $result['invoices_created']);
        $this->assertSame(1, $result['invoices_paid']);
        $this->assertCount(1, $result['invoice_numbers']);
        $invoice = Invoice::withoutGlobalScopes()->where('customer_id', $customer->id)->sole();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame('99000.00', $invoice->subtotal);
        $this->assertSame('0.00', $invoice->tax_total); // tax_billable = false (default)
        $this->assertSame('99000.00', $invoice->grand_total);
        $this->assertNotNull($invoice->paid_at);

        // Hidden renewal subscription — Cancelled (invisible to GenerateDueInvoices).
        $sub = Subscription::withoutGlobalScopes()->where('customer_id', $customer->id)->sole();
        $this->assertSame(SubscriptionStatus::Cancelled, $sub->status);

        // Komisi Penjualan matured — as a SIDE EFFECT of markPaid(), zero new logic.
        $this->assertSame(1, $result['sales_commission_matured']);
        $template->refresh();
        $this->assertSame(CommissionStatus::Eligible, $template->status);
        $this->assertSame('5000.00', $template->amount); // CommissionRate.recurring_amount
        $this->assertSame($invoice->id, $template->invoice_id);
    }

    public function test_renew_reuses_an_existing_unpaid_invoice_for_the_period_instead_of_creating_a_second(): void
    {
        $user = $this->actingUser();
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id, 'reseller_id' => null,
            'ppp_package_id' => $this->package(3000, 'Paket', 80000)->id,
        ]);

        // Pre-existing Draft invoice for THIS month via the hidden renewal
        // subscription (simulating a manual admin draft / another path).
        $renewalInvoice = app(RenewalInvoiceService::class);
        $ref = new \ReflectionMethod($renewalInvoice, 'renewalSubscriptionFor');
        $sub = $ref->invoke($renewalInvoice, $customer);
        $start = now()->startOfMonth();
        $preExisting = app(InvoiceService::class)->generateForPeriod(
            $sub, $start->copy(), $start->copy()->endOfMonth(), $start->copy(),
            overrideAmount: 80000.0, overrideDescription: 'Draft manual', applyTax: false,
        );
        $this->assertSame(InvoiceStatus::Draft, $preExisting->status);

        $result = $this->service->renew($user, $customer, null);

        // No second invoice — the existing one was reused and paid.
        $this->assertSame(1, Invoice::withoutGlobalScopes()->where('customer_id', $customer->id)->count());
        $this->assertSame(0, $result['invoices_created']);
        $this->assertSame(1, $result['invoices_paid']);
        $this->assertSame(InvoiceStatus::Paid, $preExisting->fresh()->status);
    }

    public function test_renew_with_tax_billable_customer_runs_the_tax_engine_dpp_plus_ppn(): void
    {
        $user = $this->actingUser();
        $package = $this->package(3000, 'Paket', 100000);
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id, 'reseller_id' => null,
            'ppp_package_id' => $package->id,
            'tax_billable' => true,
        ]);

        // Configure a real PPN component + direct-retail (customer-borne) policy.
        $component = TaxComponent::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'PPN', 'name' => 'PPN', 'rate' => 11,
            'type' => TaxComponentType::Percentage,
            'is_active' => true, 'effective_from' => now()->subYear()->toDateString(), 'effective_to' => null,
        ]);
        ResellerTaxPolicy::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null, // direct-retail
            'tax_component_id' => $component->id,
            'burden' => TaxBurden::CustomerBorne->value,
            'is_active' => true, 'effective_from' => now()->subYear()->toDateString(), 'effective_to' => null,
        ]);

        $this->service->renew($user, $customer, null);

        $invoice = Invoice::withoutGlobalScopes()->where('customer_id', $customer->id)->sole();
        $this->assertSame('100000.00', $invoice->subtotal);      // sell_price = DPP
        $this->assertSame('11000.00', $invoice->tax_total);       // PPN 11% on top
        $this->assertSame('111000.00', $invoice->grand_total);
    }
}
