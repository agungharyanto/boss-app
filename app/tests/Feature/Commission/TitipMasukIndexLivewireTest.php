<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Enums\NetworkProfileGroupType;
use App\Enums\TitipDepositStatus;
use App\Livewire\Commission\TitipMasukIndex;
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
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TitipMasukIndexLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(Tenant $tenant, string $role = 'superadmin'): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function titipRow(
        Tenant $tenant,
        string $customerName,
        CommissionStatus $status = CommissionStatus::Eligible,
        ?Referrer $referrer = null,
        float $amount = 3000,
        ?float $gross = 150000,
        TitipDepositStatus $deposit = TitipDepositStatus::BelumSetor,
    ): CommissionLedger {
        $referrer ??= Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'name' => $customerName,
            'referred_by_referrer_id' => $referrer->id,
        ]);

        return CommissionLedger::factory()->create([
            'tenant_id' => $tenant->id,
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Titip->value,
            'status' => $status,
            'amount' => $amount,
            'gross_amount' => $gross,
            'deposit_status' => $deposit,
            'payment_period' => now()->startOfMonth()->toDateString(),
        ]);
    }

    private function monthlyRow(Tenant $tenant, string $customerName, string $scheme = 'recurring', float $amount = 5000): CommissionLedger
    {
        $r = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $c = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'name' => $customerName, 'referred_by_referrer_id' => $r->id]);

        return CommissionLedger::factory()->create([
            'tenant_id' => $tenant->id, 'referrer_id' => $r->id, 'customer_id' => $c->id,
            'scheme' => $scheme, 'status' => CommissionStatus::Eligible, 'amount' => $amount,
        ]);
    }

    public function test_lists_both_titip_and_monthly_rows_but_never_null_scheme_templates(): void
    {
        $tenant = Tenant::factory()->create();
        $this->titipRow($tenant, 'Pelanggan Titip');
        $this->monthlyRow($tenant, 'Pelanggan Recurring');

        // A null-scheme template row (v0.9.4, belum matang) never appears.
        $r = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $c = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'name' => 'Template Belum Matang', 'referred_by_referrer_id' => $r->id]);
        CommissionLedger::factory()->create([
            'tenant_id' => $tenant->id, 'referrer_id' => $r->id, 'customer_id' => $c->id,
            'scheme' => null, 'status' => CommissionStatus::Pending, 'amount' => null,
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(TitipMasukIndex::class)
            ->assertSee('Pelanggan Titip')
            ->assertSee('Pelanggan Recurring')
            ->assertDontSee('Template Belum Matang');
    }

    public function test_scheme_filter_narrows_to_titip_or_bulanan(): void
    {
        $tenant = Tenant::factory()->create();
        $this->titipRow($tenant, 'Pelanggan Titip');
        $this->monthlyRow($tenant, 'Pelanggan Bulanan');

        Livewire::actingAs($this->admin($tenant))
            ->test(TitipMasukIndex::class)
            ->set('schemeFilter', 'titip')
            ->assertSee('Pelanggan Titip')
            ->assertDontSee('Pelanggan Bulanan')
            ->set('schemeFilter', 'bulanan')
            ->assertSee('Pelanggan Bulanan')
            ->assertDontSee('Pelanggan Titip');
    }

    public function test_pay_monthly_row_flips_an_eligible_monthly_commission_to_paid(): void
    {
        $tenant = Tenant::factory()->create();
        $row = $this->monthlyRow($tenant, 'Bulanan', 'recurring', 5000);

        Livewire::actingAs($this->admin($tenant))
            ->test(TitipMasukIndex::class)
            ->call('payMonthlyRow', $row->id)
            ->assertHasNoErrors();

        $row->refresh();
        $this->assertSame(CommissionStatus::Paid, $row->status);
        $this->assertNotNull($row->paid_at);
        $this->assertNull($row->payment_proof_path); // bulanan tidak butuh bukti
    }

    public function test_pay_monthly_row_rejects_a_row_whose_package_payout_window_is_closed(): void
    {
        Carbon::setTestNow('2026-09-20'); // di luar 5-7

        $tenant = Tenant::factory()->create();
        $ref = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $group = NetworkProfileGroup::factory()->create(['tenant_id' => $tenant->id, 'type' => NetworkProfileGroupType::Ppp]);
        $package = PppPackage::factory()->create([
            'tenant_id' => $tenant->id, 'network_profile_group_id' => $group->id,
            'bandwidth_profile_id' => BandwidthProfile::factory()->create(['tenant_id' => $tenant->id])->id,
            'sell_price' => 100000,
        ]);
        CommissionRate::factory()->create([
            'ppp_package_id' => $package->id, 'recurring_amount' => 5000, 'is_active' => true,
            'payout_window_start_day' => 5, 'payout_window_end_day' => 7,
        ]);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id, 'reseller_id' => null,
            'ppp_package_id' => $package->id, 'referred_by_referrer_id' => $ref->id,
        ]);
        $row = CommissionLedger::factory()->create([
            'tenant_id' => $tenant->id, 'referrer_id' => $ref->id, 'customer_id' => $customer->id,
            'scheme' => 'recurring', 'status' => CommissionStatus::Eligible, 'amount' => 5000,
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(TitipMasukIndex::class)
            ->call('payMonthlyRow', $row->id)
            ->assertHasErrors('monthlyPay');

        $this->assertSame(CommissionStatus::Eligible, $row->fresh()->status);
    }

    public function test_status_filter_narrows_the_list(): void
    {
        $tenant = Tenant::factory()->create();
        $this->titipRow($tenant, 'Yang Eligible', CommissionStatus::Eligible);
        $this->titipRow($tenant, 'Yang Paid', CommissionStatus::Paid);

        Livewire::actingAs($this->admin($tenant))
            ->test(TitipMasukIndex::class)
            ->set('statusFilter', CommissionStatus::Paid->value)
            ->assertSee('Yang Paid')
            ->assertDontSee('Yang Eligible');
    }

    public function test_search_matches_referrer_or_customer_name(): void
    {
        $tenant = Tenant::factory()->create();
        $this->titipRow($tenant, 'Bambang Sudibyo');
        $this->titipRow($tenant, 'Citra Lestari');

        Livewire::actingAs($this->admin($tenant))
            ->test(TitipMasukIndex::class)
            ->set('search', 'Bambang')
            ->assertSee('Bambang Sudibyo')
            ->assertDontSee('Citra Lestari');
    }

    public function test_page_is_forbidden_without_the_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $plain = User::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($plain)
            ->test(TitipMasukIndex::class)
            ->assertForbidden();
    }

    public function test_route_and_sidebar_link_are_reachable_for_an_admin(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);

        $this->actingAs($admin)->get('/titip-masuk')->assertOk();

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee(route('web.titip-masuk.index'));
    }

    public function test_summary_cards_compute_total_commission_and_total_undeposited(): void
    {
        $tenant = Tenant::factory()->create();
        // Eligible + belum setor: dihitung di kedua kartu.
        $this->titipRow($tenant, 'A', CommissionStatus::Eligible, amount: 3000, gross: 100000, deposit: TitipDepositStatus::BelumSetor);
        // Eligible tapi sudah setor: hitung komisi, TIDAK hitung setoran belum masuk.
        $this->titipRow($tenant, 'B', CommissionStatus::Eligible, amount: 5000, gross: 200000, deposit: TitipDepositStatus::SudahSetor);
        // Paid + belum setor: TIDAK hitung komisi (bukan eligible), hitung setoran belum masuk.
        $this->titipRow($tenant, 'C', CommissionStatus::Paid, amount: 7000, gross: 50000, deposit: TitipDepositStatus::BelumSetor);

        // + komisi Bulanan (recurring) Eligible: 12000
        $this->monthlyRow($tenant, 'D', 'recurring', 12000);

        Livewire::actingAs($this->admin($tenant))
            ->test(TitipMasukIndex::class)
            ->assertViewHas('totalTitipHarusDibayar', 8000.0)       // 3000 + 5000
            ->assertViewHas('totalSetoranBelumMasuk', 150000.0)     // 100000 + 50000
            ->assertViewHas('totalBulananHarusDibayar', 12000.0)
            ->assertViewHas('totalGabungan', 20000.0);              // 8000 + 12000
    }

    public function test_rows_are_grouped_per_referrer_with_a_correct_undeposited_total(): void
    {
        $tenant = Tenant::factory()->create();
        $refA = Referrer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Referrer Alpha']);
        $refB = Referrer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Referrer Beta']);

        $this->titipRow($tenant, 'A1', referrer: $refA, gross: 100000, deposit: TitipDepositStatus::BelumSetor);
        $this->titipRow($tenant, 'A2', referrer: $refA, gross: 50000, deposit: TitipDepositStatus::BelumSetor);
        $this->titipRow($tenant, 'A3', referrer: $refA, gross: 999999, deposit: TitipDepositStatus::SudahSetor);
        $this->titipRow($tenant, 'B1', referrer: $refB, gross: 70000, deposit: TitipDepositStatus::BelumSetor);

        Livewire::actingAs($this->admin($tenant))
            ->test(TitipMasukIndex::class)
            ->assertViewHas('groups', function ($groups) use ($refA, $refB) {
                $a = $groups->firstWhere('referrer.id', $refA->id);
                $b = $groups->firstWhere('referrer.id', $refB->id);

                return $groups->count() === 2
                    && $a['tx_count'] === 3
                    && $a['total_belum_setor'] === 150000.0   // 100k + 50k, NOT the sudah-setor 999999
                    && $a['belum_setor_count'] === 2
                    && $b['total_belum_setor'] === 70000.0;
            });
    }

    public function test_mark_selected_deposited_updates_only_the_checked_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $ref = Referrer::factory()->create(['tenant_id' => $tenant->id]);

        // Skenario: Titip dicatat (layanan diperpanjang) TAPI uang cash A2
        // belum benar-benar diambil dari pelanggan — admin cuma tandai A1.
        $a1 = $this->titipRow($tenant, 'A1', referrer: $ref, deposit: TitipDepositStatus::BelumSetor);
        $a2 = $this->titipRow($tenant, 'A2', referrer: $ref, deposit: TitipDepositStatus::BelumSetor);

        $admin = $this->admin($tenant);

        Livewire::actingAs($admin)
            ->test(TitipMasukIndex::class)
            ->set('selected', [$a1->id])
            ->call('markSelectedDeposited')
            ->assertSet('flash', fn ($m) => str_contains($m, '1 transaksi'))
            ->assertSet('selected', []);

        $a1->refresh();
        $this->assertSame(TitipDepositStatus::SudahSetor, $a1->deposit_status);
        $this->assertNotNull($a1->deposited_at);
        $this->assertSame($admin->id, $a1->deposited_by);

        // A2 tidak dicentang -> tidak berubah.
        $this->assertSame(TitipDepositStatus::BelumSetor, $a2->refresh()->deposit_status);
        $this->assertNull($a2->deposited_by);
    }

    public function test_toggle_group_selection_checks_all_belum_setor_rows_of_that_referrer(): void
    {
        $tenant = Tenant::factory()->create();
        $refA = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $refB = Referrer::factory()->create(['tenant_id' => $tenant->id]);

        $a1 = $this->titipRow($tenant, 'A1', referrer: $refA, deposit: TitipDepositStatus::BelumSetor);
        $a2 = $this->titipRow($tenant, 'A2', referrer: $refA, deposit: TitipDepositStatus::BelumSetor);
        $this->titipRow($tenant, 'A3', referrer: $refA, deposit: TitipDepositStatus::SudahSetor); // excluded
        $b1 = $this->titipRow($tenant, 'B1', referrer: $refB, deposit: TitipDepositStatus::BelumSetor);

        $c = Livewire::actingAs($this->admin($tenant))
            ->test(TitipMasukIndex::class)
            ->call('toggleGroupSelection', $refA->id);

        $selected = collect($c->get('selected'))->map(fn ($i) => (int) $i)->sort()->values()->all();
        $this->assertSame([$a1->id, $a2->id], $selected);
        $this->assertNotContains($b1->id, $selected);

        // Toggle lagi -> uncheck semua.
        $c->call('toggleGroupSelection', $refA->id)->assertSet('selected', []);
    }

    public function test_mark_selected_requires_the_manage_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $ref = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $row = $this->titipRow($tenant, 'A1', referrer: $ref, deposit: TitipDepositStatus::BelumSetor);

        // View-only user: can open the page, cannot mark deposited.
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo(Permission::firstOrCreate(['name' => 'commission_ledger.view', 'guard_name' => 'web']));

        Livewire::actingAs($viewer)
            ->test(TitipMasukIndex::class)
            ->assertOk()
            ->set('selected', [$row->id])
            ->call('markSelectedDeposited')
            ->assertForbidden();

        $this->assertSame(TitipDepositStatus::BelumSetor, $row->refresh()->deposit_status);
    }

    public function test_status_and_deposit_filters_are_independent_and_combine_with_and(): void
    {
        $tenant = Tenant::factory()->create();
        // Match kedua filter.
        $this->titipRow($tenant, 'Eligible Belum Setor', CommissionStatus::Eligible, deposit: TitipDepositStatus::BelumSetor);
        // Match status saja.
        $this->titipRow($tenant, 'Eligible Sudah Setor', CommissionStatus::Eligible, deposit: TitipDepositStatus::SudahSetor);
        // Match deposit saja.
        $this->titipRow($tenant, 'Paid Belum Setor', CommissionStatus::Paid, deposit: TitipDepositStatus::BelumSetor);

        Livewire::actingAs($this->admin($tenant))
            ->test(TitipMasukIndex::class)
            ->set('statusFilter', CommissionStatus::Eligible->value)
            ->set('depositFilter', TitipDepositStatus::BelumSetor->value)
            ->assertSee('Eligible Belum Setor')
            ->assertDontSee('Eligible Sudah Setor')
            ->assertDontSee('Paid Belum Setor');
    }

    public function test_deposit_status_filter_narrows_the_list(): void
    {
        $tenant = Tenant::factory()->create();
        $this->titipRow($tenant, 'Belum Bayar Ke Kantor', deposit: TitipDepositStatus::BelumSetor);
        $this->titipRow($tenant, 'Sudah Lunas Ke Kantor', deposit: TitipDepositStatus::SudahSetor);

        Livewire::actingAs($this->admin($tenant))
            ->test(TitipMasukIndex::class)
            ->set('depositFilter', TitipDepositStatus::SudahSetor->value)
            ->assertSee('Sudah Lunas Ke Kantor')
            ->assertDontSee('Belum Bayar Ke Kantor');
    }
}
