<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Enums\NetworkProfileGroupType;
use App\Livewire\Commission\MonthlyPayoutIndex;
use App\Models\BandwidthProfile;
use App\Models\CommissionLedger;
use App\Models\CommissionRate;
use App\Models\Customer;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.9.11 (Payout Komisi) — halaman batch payout komisi bulanan
 * (recurring/limited_count).
 *
 * AMANDEMEN (2026-09-05) — jendela tanggal BUKAN LAGI satu aturan global
 * "5-7" — sekarang per `CommissionRate` (per paket). File ini ditulis
 * ulang total: tidak ada lagi banner/tombol berbasis satu flag halaman,
 * melainkan status per-baris/per-grup ("payable_count") yang dihitung
 * dari rate masing-masing paket. Guard sesungguhnya tetap di
 * `CommissionPayoutService` (lihat CommissionPayoutServiceTest) — file ini
 * membuktikan wiring UI-nya.
 */
class MonthlyPayoutIndexLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function admin(Tenant $tenant, string $role = 'superadmin'): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function packageWithWindow(Tenant $tenant, ?int $start, ?int $end): PppPackage
    {
        $group = NetworkProfileGroup::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => NetworkProfileGroupType::Ppp,
        ]);

        $package = PppPackage::factory()->create([
            'tenant_id' => $tenant->id,
            'network_profile_group_id' => $group->id,
            'bandwidth_profile_id' => BandwidthProfile::factory()->create(['tenant_id' => $tenant->id])->id,
        ]);

        CommissionRate::factory()->create([
            'ppp_package_id' => $package->id,
            'recurring_amount' => 5000,
            'payout_window_start_day' => $start,
            'payout_window_end_day' => $end,
        ]);

        return $package;
    }

    private function monthlyRow(
        Tenant $tenant,
        Referrer $referrer,
        CommissionScheme $scheme = CommissionScheme::Recurring,
        ?PppPackage $package = null,
    ): CommissionLedger {
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $package?->id,
        ]);

        return CommissionLedger::factory()->create([
            'tenant_id' => $tenant->id,
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'scheme' => $scheme->value,
            'status' => CommissionStatus::Eligible,
            'amount' => 5000,
        ]);
    }

    public function test_page_is_forbidden_without_the_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $plain = User::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($plain)->test(MonthlyPayoutIndex::class)->assertForbidden();
    }

    public function test_lists_eligible_recurring_and_limited_count_rows_grouped_by_referrer(): void
    {
        $tenant = Tenant::factory()->create();
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Kamisem']);
        $this->monthlyRow($tenant, $referrer, CommissionScheme::Recurring);
        $this->monthlyRow($tenant, $referrer, CommissionScheme::LimitedCount);

        Livewire::actingAs($this->admin($tenant))
            ->test(MonthlyPayoutIndex::class)
            ->assertSee('Kamisem')
            ->assertSee('2 baris');
    }

    public function test_a_row_with_no_window_configured_is_payable_and_the_group_button_is_enabled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00:00'));

        $tenant = Tenant::factory()->create();
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $this->monthlyRow($tenant, $referrer);

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(MonthlyPayoutIndex::class)
            ->assertSee('Kapan saja')
            ->assertSee('1 bisa dibayar sekarang')
            ->html();

        $this->assertDoesNotMatchRegularExpression('/wire:click="payReferrer\(\d+\)"[^>]*\bdisabled\b(?!:)/s', $html);
    }

    public function test_a_row_whose_rate_window_is_currently_closed_shows_tutup_and_disables_its_groups_button_when_it_is_the_only_row(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00:00'));

        $tenant = Tenant::factory()->create();
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $closedPackage = $this->packageWithWindow($tenant, 5, 7);
        $this->monthlyRow($tenant, $referrer, CommissionScheme::Recurring, $closedPackage);

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(MonthlyPayoutIndex::class)
            ->assertSee('Tgl 5-7')
            ->assertSee('Tutup')
            ->assertSee('0 bisa dibayar sekarang')
            ->html();

        $this->assertMatchesRegularExpression('/wire:click="payReferrer\(\d+\)"[^>]*\bdisabled\b(?!:)/s', $html);
    }

    public function test_calling_pay_referrer_directly_when_the_only_row_is_closed_pays_nothing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00:00'));

        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $closedPackage = $this->packageWithWindow($tenant, 5, 7);
        $row = $this->monthlyRow($tenant, $referrer, CommissionScheme::Recurring, $closedPackage);

        Livewire::actingAs($admin)
            ->test(MonthlyPayoutIndex::class)
            ->call('payReferrer', $referrer->id)
            ->assertSee('Tidak ada baris komisi yang memenuhi syarat');

        $this->assertSame(CommissionStatus::Eligible, $row->fresh()->status);
    }

    public function test_process_payout_pays_only_the_row_whose_window_is_open_and_skips_the_closed_one(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-06 10:00:00'));

        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $openPackage = $this->packageWithWindow($tenant, 5, 7);
        $closedPackage = $this->packageWithWindow($tenant, 20, 25);
        $openRow = $this->monthlyRow($tenant, $referrer, CommissionScheme::Recurring, $openPackage);
        $closedRow = $this->monthlyRow($tenant, $referrer, CommissionScheme::LimitedCount, $closedPackage);

        Livewire::actingAs($admin)
            ->test(MonthlyPayoutIndex::class)
            ->call('payReferrer', $referrer->id)
            ->assertSee('1 baris komisi bulanan ditandai dibayar');

        $this->assertSame(CommissionStatus::Paid, $openRow->fresh()->status);
        $this->assertSame(CommissionStatus::Eligible, $closedRow->fresh()->status);
        $this->assertSame($admin->id, $openRow->fresh()->paid_by);
    }

    public function test_a_view_only_user_cannot_process_payout(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00:00'));

        $tenant = Tenant::factory()->create();
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo('commission_ledger.view');
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $row = $this->monthlyRow($tenant, $referrer);

        Livewire::actingAs($viewer)
            ->test(MonthlyPayoutIndex::class)
            ->call('payReferrer', $referrer->id)
            ->assertForbidden();

        $this->assertSame(CommissionStatus::Eligible, $row->fresh()->status);
    }
}
