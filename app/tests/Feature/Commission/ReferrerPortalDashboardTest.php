<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Enums\TitipDepositStatus;
use App\Livewire\ReferrerPortal\Dashboard;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sprint "perpanjang-daftar-pelanggan" LANGKAH 3 — Portal Referrer
 * disederhanakan: hanya Profil Saya + Rekap Komisi + Rekap Titip. Alur
 * pencatatan (dulu "Catat Titip") pindah ke Daftar Pelanggan / aksi
 * Perpanjang.
 */
class ReferrerPortalDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Referrer $referrer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->referrer = Referrer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);
    }

    public function test_dashboard_shows_profile_and_the_two_recap_tables_only(): void
    {
        Livewire::actingAs($this->referrer->user)
            ->test(Dashboard::class)
            ->assertSeeInOrder(['Profil Saya', 'Rekap Komisi', 'Rekap Titip'])
            ->assertDontSee('Catat Titip')
            ->assertDontSee('Daftar Pelanggan yang Anda');
    }

    public function test_recap_tables_are_split_by_scheme(): void
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'reseller_id' => null]);

        CommissionLedger::factory()->create([
            'tenant_id' => $this->tenant->id,
            'referrer_id' => $this->referrer->id,
            'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Recurring->value,
            'status' => CommissionStatus::Eligible,
            'amount' => 5000,
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

        Livewire::actingAs($this->referrer->user)
            ->test(Dashboard::class)
            ->assertViewHas('commissionEntries', fn ($c) => $c->count() === 1 && $c->first()->scheme->value === 'recurring')
            ->assertViewHas('titipEntries', fn ($t) => $t->count() === 1 && $t->first()->scheme->value === 'titip');
    }

    public function test_summary_numbers_are_computed(): void
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'reseller_id' => null]);

        // Komisi referral: eligible dihitung, pending tidak.
        CommissionLedger::factory()->create([
            'tenant_id' => $this->tenant->id, 'referrer_id' => $this->referrer->id, 'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Recurring->value, 'status' => CommissionStatus::Eligible, 'amount' => 5000,
        ]);
        CommissionLedger::factory()->create([
            'tenant_id' => $this->tenant->id, 'referrer_id' => $this->referrer->id, 'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Recurring->value, 'status' => CommissionStatus::Pending, 'amount' => 9999,
        ]);

        // Titip: gross terkumpul semua, sisa disetor hanya yang belum_setor.
        CommissionLedger::factory()->create([
            'tenant_id' => $this->tenant->id, 'referrer_id' => $this->referrer->id, 'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Titip->value, 'status' => CommissionStatus::Eligible, 'amount' => 3000,
            'gross_amount' => 100000, 'deposit_status' => TitipDepositStatus::BelumSetor,
            'payment_period' => now()->startOfMonth()->toDateString(),
        ]);
        CommissionLedger::factory()->create([
            'tenant_id' => $this->tenant->id, 'referrer_id' => $this->referrer->id, 'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Titip->value, 'status' => CommissionStatus::Eligible, 'amount' => 3000,
            'gross_amount' => 250000, 'deposit_status' => TitipDepositStatus::SudahSetor,
            'payment_period' => now()->startOfMonth()->toDateString(),
        ]);

        Livewire::actingAs($this->referrer->user)
            ->test(Dashboard::class)
            ->assertViewHas('totalKomisi', 5000.0)
            ->assertViewHas('totalTitipTerkumpul', 350000.0)
            ->assertViewHas('sisaPerluDisetor', 100000.0);
    }

    public function test_referrer_can_update_their_own_name(): void
    {
        Livewire::actingAs($this->referrer->user)
            ->test(Dashboard::class)
            ->set('name', 'Nama Baru')
            ->call('updateName')
            ->assertHasNoErrors()
            ->assertSet('nameUpdated', true);

        $this->assertSame('Nama Baru', $this->referrer->fresh()->name);
    }

    public function test_dashboard_exposes_no_titip_recording_or_ledger_mutation_methods(): void
    {
        $methods = get_class_methods(Dashboard::class);

        foreach (['startTitip', 'sendTitipOtp', 'submitTitip', 'recordTitip', 'deleteTitip', 'editTitip'] as $forbidden) {
            $this->assertNotContains($forbidden, $methods);
        }
    }
}
