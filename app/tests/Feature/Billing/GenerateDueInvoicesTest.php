<?php

namespace Tests\Feature\Billing;

use App\Enums\NetworkProfileGroupType;
use App\Enums\SubscriptionStatus;
use App\Models\BandwidthProfile;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * v0.12.2 — belum ada test file untuk command ini sebelum ini sama sekali.
 * Fokus: guard sell_price=0 (`PppPackage::hasZeroSellPrice()`, satu sumber
 * kebenaran bareng RenewalInvoiceService/PppoeVlan10MigrationService) +
 * jalur normal command tetap benar.
 */
class GenerateDueInvoicesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `billing_cycle_day` yang, dikombinasikan dengan `started_at` = hari
     * ini, membuat `InvoiceService::resolvePeriod()`'s
     * `cycleDateOnOrAfter()` menghasilkan due_date PERSIS N hari dari
     * sekarang — termasuk saat N hari melintasi batas bulan (logic
     * self-correcting: day-of-month yang diminta lebih kecil dari hari ini
     * -> maju ke bulan berikutnya).
     */
    private function dueInDaysCycleDay(int $days): int
    {
        return Carbon::now()->addDays($days)->day;
    }

    private function package(Tenant $tenant, float $sellPrice = 100000): PppPackage
    {
        $group = NetworkProfileGroup::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => NetworkProfileGroupType::Ppp,
        ]);

        return PppPackage::factory()->create([
            'tenant_id' => $tenant->id,
            'network_profile_group_id' => $group->id,
            'bandwidth_profile_id' => BandwidthProfile::factory()->create(['tenant_id' => $tenant->id])->id,
            'sell_price' => $sellPrice,
        ]);
    }

    private function activeSubscriptionDueInDays(Tenant $tenant, Customer $customer, int $days): Subscription
    {
        return Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'status' => SubscriptionStatus::Active->value,
            'started_at' => Carbon::now()->toDateString(),
            'billing_cycle_day' => $this->dueInDaysCycleDay($days),
        ]);
    }

    public function test_generates_an_invoice_for_a_subscription_due_in_lead_days(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $subscription = $this->activeSubscriptionDueInDays($tenant, $customer, 7);

        $this->artisan('app:generate-due-invoices')->assertExitCode(0);

        $this->assertSame(
            1,
            Invoice::withoutGlobalScopes()->where('subscription_id', $subscription->id)->count(),
        );
    }

    public function test_a_subscription_not_due_within_lead_days_gets_no_invoice(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $subscription = $this->activeSubscriptionDueInDays($tenant, $customer, 20);

        $this->artisan('app:generate-due-invoices')->assertExitCode(0);

        $this->assertSame(
            0,
            Invoice::withoutGlobalScopes()->where('subscription_id', $subscription->id)->count(),
        );
    }

    public function test_skips_a_subscription_whose_customer_package_has_zero_sell_price(): void
    {
        $tenant = Tenant::factory()->create();
        $freePackage = $this->package($tenant, 0);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'ppp_package_id' => $freePackage->id,
        ]);
        $subscription = $this->activeSubscriptionDueInDays($tenant, $customer, 7);

        $this->artisan('app:generate-due-invoices')->assertExitCode(0);

        $this->assertSame(
            0,
            Invoice::withoutGlobalScopes()->where('subscription_id', $subscription->id)->count(),
        );
    }

    /**
     * Bukti "bukan skip permanen" (instruksi eksplisit): begitu pelanggan
     * pindah ke paket berbayar, run BERIKUTNYA tidak lagi men-skip
     * subscription yang sama — tidak ada state "sudah pernah di-skip" yang
     * perlu dibersihkan secara manual.
     */
    public function test_switching_the_customer_off_the_zero_price_package_stops_the_skip_on_the_next_run(): void
    {
        $tenant = Tenant::factory()->create();
        $freePackage = $this->package($tenant, 0);
        $paidPackage = $this->package($tenant, 150000);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'ppp_package_id' => $freePackage->id,
        ]);
        $subscription = $this->activeSubscriptionDueInDays($tenant, $customer, 7);

        $this->artisan('app:generate-due-invoices')->assertExitCode(0);
        $this->assertSame(0, Invoice::withoutGlobalScopes()->where('subscription_id', $subscription->id)->count());

        $customer->update(['ppp_package_id' => $paidPackage->id]);

        $this->artisan('app:generate-due-invoices')->assertExitCode(0);
        $this->assertSame(1, Invoice::withoutGlobalScopes()->where('subscription_id', $subscription->id)->count());
    }

    /**
     * Pelanggan tanpa ppp_package_id sama sekali (customer belum pernah
     * di-assign paket) BUKAN "paket gratis struktural" — perilaku lama
     * (amount jatuh ke subscription->monthly_amount, invoice tetap
     * terbit) tidak berubah oleh guard ini.
     */
    public function test_a_customer_with_no_package_at_all_is_not_treated_as_zero_price(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'ppp_package_id' => null,
        ]);
        $subscription = $this->activeSubscriptionDueInDays($tenant, $customer, 7);

        $this->artisan('app:generate-due-invoices')->assertExitCode(0);

        $this->assertSame(
            1,
            Invoice::withoutGlobalScopes()->where('subscription_id', $subscription->id)->count(),
        );
    }
}
