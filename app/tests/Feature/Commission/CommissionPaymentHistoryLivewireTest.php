<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Livewire\Commission\CommissionPaymentHistory;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bagian E (v0.9.12) — Riwayat Pembayaran Komisi + grafik.
 */
class CommissionPaymentHistoryLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(Tenant $tenant): User
    {
        $u = User::factory()->create(['tenant_id' => $tenant->id]);
        $u->assignRole('superadmin');

        return $u;
    }

    private function paidRow(Tenant $tenant, string $scheme, float $amount, Carbon $paidAt, ?Referrer $referrer = null): CommissionLedger
    {
        $referrer ??= Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'referred_by_referrer_id' => $referrer->id]);

        return CommissionLedger::factory()->create([
            'tenant_id' => $tenant->id, 'referrer_id' => $referrer->id, 'customer_id' => $customer->id,
            'scheme' => $scheme, 'status' => CommissionStatus::Paid, 'amount' => $amount,
            'paid_at' => $paidAt, 'paid_by' => $this->admin($tenant)->id,
        ]);
    }

    public function test_lists_only_paid_rows_both_titip_and_monthly(): void
    {
        $tenant = Tenant::factory()->create();
        Carbon::setTestNow('2026-09-15');

        $this->paidRow($tenant, CommissionScheme::Titip->value, 3000, Carbon::parse('2026-09-10'));
        $this->paidRow($tenant, CommissionScheme::Recurring->value, 5000, Carbon::parse('2026-09-12'));

        // Eligible (belum dibayar) — tidak muncul.
        $r = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $c = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'name' => 'Belum Dibayar', 'referred_by_referrer_id' => $r->id]);
        CommissionLedger::factory()->create([
            'tenant_id' => $tenant->id, 'referrer_id' => $r->id, 'customer_id' => $c->id,
            'scheme' => CommissionScheme::Titip->value, 'status' => CommissionStatus::Eligible, 'amount' => 9999,
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CommissionPaymentHistory::class)
            ->assertViewHas('totalTitip', 3000.0)
            ->assertViewHas('totalBulanan', 5000.0)
            ->assertViewHas('totalAll', 8000.0)
            ->assertViewHas('countAll', 2)
            ->assertDontSee('Belum Dibayar');
    }

    public function test_chart_series_buckets_by_month_and_splits_by_type(): void
    {
        $tenant = Tenant::factory()->create();
        Carbon::setTestNow('2026-09-15');

        $this->paidRow($tenant, CommissionScheme::Titip->value, 1000, Carbon::parse('2026-08-05'));
        $this->paidRow($tenant, CommissionScheme::Titip->value, 2000, Carbon::parse('2026-09-05'));
        $this->paidRow($tenant, CommissionScheme::LimitedCount->value, 7000, Carbon::parse('2026-09-20'));

        Livewire::actingAs($this->admin($tenant))
            ->test(CommissionPaymentHistory::class)
            ->set('from', '2026-08-01')
            ->set('to', '2026-09-30')
            ->assertViewHas('chartSeries', function ($series) {
                return count($series['labels']) === 2 // Agu/Agt + Sep
                    && $series['titip'] === [1000.0, 2000.0]
                    && $series['bulanan'] === [0.0, 7000.0];
            });
    }

    public function test_referrer_and_date_filters_apply(): void
    {
        $tenant = Tenant::factory()->create();
        Carbon::setTestNow('2026-09-15');
        $refA = Referrer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Ref A']);
        $refB = Referrer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Ref B']);

        $this->paidRow($tenant, CommissionScheme::Titip->value, 3000, Carbon::parse('2026-09-10'), $refA);
        $this->paidRow($tenant, CommissionScheme::Titip->value, 4000, Carbon::parse('2026-09-10'), $refB);
        $this->paidRow($tenant, CommissionScheme::Titip->value, 5000, Carbon::parse('2026-07-10'), $refA);

        Livewire::actingAs($this->admin($tenant))
            ->test(CommissionPaymentHistory::class)
            ->set('from', '2026-09-01')
            ->set('to', '2026-09-30')
            ->set('referrerId', (string) $refA->id)
            ->assertViewHas('totalAll', 3000.0);
    }

    public function test_forbidden_without_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $tenant->id]);
        $u->assignRole('teknisi');

        Livewire::actingAs($u)->test(CommissionPaymentHistory::class)->assertForbidden();
    }
}
