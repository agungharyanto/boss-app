<?php

namespace Tests\Feature\Customers;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Enums\NetworkProfileGroupType;
use App\Enums\ReferrerType;
use App\Enums\TitipDepositStatus;
use App\Enums\WhatsappEventType;
use App\Livewire\Customers\CustomerIndex;
use App\Models\BandwidthProfile;
use App\Models\CommissionLedger;
use App\Models\CommissionRate;
use App\Models\Customer;
use App\Models\CustomerTimelineEntry;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappMessageTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Sprint "perpanjang-daftar-pelanggan" LANGKAH 2 — aksi "Perpanjang" di
 * Daftar Pelanggan (OTP WhatsApp + ganti paket opsional + komisi Titip
 * untuk Referrer Sales/Freelance).
 */
class CustomerRenewalLivewireTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        $this->tenant = Tenant::factory()->create();

        WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::ReferrerActionOtp,
            'content' => 'Kode {otp_code} berlaku {otp_minutes} menit — {referrer_name}. Untuk: {action_label}.',
            'is_active' => true,
        ]);
    }

    private function referrerUser(ReferrerType $type): Referrer
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        return Referrer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'type' => $type,
            'phone' => '081277700011',
            'is_active' => true,
        ]);
    }

    private function adminUser(): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'customers.view', 'guard_name' => 'web']));
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'customers.manage', 'guard_name' => 'web']));

        return $user;
    }

    private function packageWithTitipRate(?float $titip, string $name = 'Paket Uji', float $sellPrice = 150000): PppPackage
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

    private function customer(?PppPackage $package): Customer
    {
        return Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $package?->id,
        ]);
    }

    private function otpCodeFor(Referrer $referrer, Customer $customer): string
    {
        return Cache::get("referrer-otp:{$referrer->id}:renewal:{$customer->id}")['code'];
    }

    public function test_renew_by_a_sales_referrer_creates_an_eligible_titip_commission(): void
    {
        $referrer = $this->referrerUser(ReferrerType::Sales);
        $customer = $this->customer($this->packageWithTitipRate(3000));

        Livewire::actingAs($referrer->user)->test(CustomerIndex::class)
            ->call('openRenew', $customer->id)
            ->assertSet('renewModalOpen', true)
            ->call('sendRenewOtp')
            ->assertSet('renewOtpSent', true)
            ->set('renewOtp', $this->otpCodeFor($referrer, $customer))
            ->call('verifyRenewOtp')
            ->assertSet('renewOtpVerified', true)
            ->call('submitRenew')
            ->assertSet('renewModalOpen', false)
            ->assertSet('renewFlash', fn ($m) => str_contains($m, 'komisi Titip'));

        $row = CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)
            ->where('scheme', CommissionScheme::Titip->value)
            ->sole();

        $this->assertSame($referrer->id, $row->referrer_id);
        $this->assertSame(CommissionStatus::Eligible, $row->status);
        $this->assertSame('3000.00', $row->amount);
        $this->assertSame(now()->startOfMonth()->toDateString(), $row->payment_period->toDateString());

        $this->assertDatabaseHas('customer_timeline_entries', [
            'customer_id' => $customer->id,
            'event_type' => 'subscription_renewed',
        ]);
    }

    public function test_renew_by_a_teknisi_referrer_records_a_paid_period_row_without_a_commission(): void
    {
        $referrer = $this->referrerUser(ReferrerType::Teknisi);
        $customer = $this->customer($this->packageWithTitipRate(3000));

        Livewire::actingAs($referrer->user)->test(CustomerIndex::class)
            ->call('openRenew', $customer->id)
            ->call('sendRenewOtp')
            ->set('renewOtp', $this->otpCodeFor($referrer, $customer))
            ->call('verifyRenewOtp')
            ->call('submitRenew')
            ->assertSet('renewFlash', fn ($m) => str_contains($m, 'dicatat') && ! str_contains($m, 'komisi'));

        // Baris titip DIBUAT sebagai penanda "periode ini sudah dibayar",
        // amount NULL (tidak ada komisi), Teknisi memegang cash -> belum_setor.
        $row = CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->where('scheme', CommissionScheme::Titip->value)->sole();
        $this->assertNull($row->amount);
        $this->assertSame($referrer->id, $row->referrer_id);
        $this->assertSame(TitipDepositStatus::BelumSetor, $row->deposit_status);

        $this->assertDatabaseHas('customer_timeline_entries', [
            'customer_id' => $customer->id,
            'event_type' => 'subscription_renewed',
        ]);
    }

    public function test_admin_without_a_linked_referrer_records_a_paid_period_row_marked_deposited(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer($this->packageWithTitipRate(3000));

        Livewire::actingAs($admin)->test(CustomerIndex::class)
            ->call('openRenew', $customer->id)
            ->call('submitRenew')
            ->assertSet('renewModalOpen', false)
            ->assertSet('renewFlash', fn ($m) => str_contains($m, 'dicatat'));

        // Dicatat admin langsung: referrer_id NULL, amount NULL,
        // deposit_status sudah_setor (tidak ada Referrer yang pegang cash).
        $row = CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->where('scheme', CommissionScheme::Titip->value)->sole();
        $this->assertNull($row->amount);
        $this->assertNull($row->referrer_id);
        $this->assertSame(TitipDepositStatus::SudahSetor, $row->deposit_status);
        $this->assertSame($admin->id, $row->deposited_by);

        $this->assertDatabaseHas('customer_timeline_entries', [
            'customer_id' => $customer->id,
            'event_type' => 'subscription_renewed',
        ]);
    }

    public function test_perpanjang_is_blocked_for_a_referrer_until_the_otp_is_verified(): void
    {
        $referrer = $this->referrerUser(ReferrerType::Sales);
        $customer = $this->customer($this->packageWithTitipRate(3000));

        Livewire::actingAs($referrer->user)->test(CustomerIndex::class)
            ->call('openRenew', $customer->id)
            ->call('sendRenewOtp')
            ->call('submitRenew')
            ->assertHasErrors('renewOtp')
            ->assertSet('renewModalOpen', true);

        $this->assertDatabaseMissing('customer_timeline_entries', [
            'customer_id' => $customer->id,
            'event_type' => 'subscription_renewed',
        ]);
    }

    public function test_a_wrong_otp_does_not_verify(): void
    {
        $referrer = $this->referrerUser(ReferrerType::Sales);
        $customer = $this->customer($this->packageWithTitipRate(3000));

        Livewire::actingAs($referrer->user)->test(CustomerIndex::class)
            ->call('openRenew', $customer->id)
            ->call('sendRenewOtp')
            ->set('renewOtp', '000000')
            ->call('verifyRenewOtp')
            ->assertSet('renewOtpVerified', false)
            ->assertHasErrors('renewOtp');
    }

    public function test_changing_the_package_updates_ppp_package_id_and_prices_commission_off_the_new_package(): void
    {
        $referrer = $this->referrerUser(ReferrerType::Freelance);
        $from = $this->packageWithTitipRate(3000, 'Lama-10Mbps');
        $to = $this->packageWithTitipRate(4000, 'Baru-20Mbps');
        $customer = $this->customer($from);

        Livewire::actingAs($referrer->user)->test(CustomerIndex::class)
            ->call('openRenew', $customer->id)
            ->set('renewNewPackageId', (string) $to->id)
            ->call('sendRenewOtp')
            ->set('renewOtp', $this->otpCodeFor($referrer, $customer))
            ->call('verifyRenewOtp')
            ->call('submitRenew');

        $this->assertSame($to->id, $customer->fresh()->ppp_package_id);

        $row = CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)
            ->where('scheme', CommissionScheme::Titip->value)
            ->sole();
        $this->assertSame('4000.00', $row->amount);
    }

    public function test_renewal_for_a_customer_in_another_tenant_404s(): void
    {
        $referrer = $this->referrerUser(ReferrerType::Sales);
        $foreign = Customer::factory()->create(['reseller_id' => null]);

        Livewire::actingAs($referrer->user)->test(CustomerIndex::class)
            ->call('openRenew', $foreign->id)
            ->assertStatus(404);
    }

    public function test_the_component_exposes_no_ledger_edit_or_delete_action(): void
    {
        $methods = get_class_methods(CustomerIndex::class);

        foreach (['deleteLedger', 'editLedger', 'voidCommission', 'deleteTitip', 'updateCommission'] as $forbidden) {
            $this->assertNotContains($forbidden, $methods);
        }
    }

    public function test_a_second_renewal_for_the_same_customer_and_month_is_hard_blocked_for_a_referrer(): void
    {
        $referrer = $this->referrerUser(ReferrerType::Sales);
        $customer = $this->customer($this->packageWithTitipRate(3000));

        // First renewal — succeeds, creates the titip row.
        Livewire::actingAs($referrer->user)->test(CustomerIndex::class)
            ->call('openRenew', $customer->id)
            ->call('sendRenewOtp')
            ->set('renewOtp', $this->otpCodeFor($referrer, $customer))
            ->call('verifyRenewOtp')
            ->call('submitRenew')
            ->assertSet('renewFlash', fn ($m) => str_contains($m, 'dicatat'));

        $this->assertSame(1, CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->where('scheme', CommissionScheme::Titip->value)->count());

        // Second — modal opens straight into the "already paid" state, no OTP.
        $second = Livewire::actingAs($referrer->user)->test(CustomerIndex::class)
            ->call('openRenew', $customer->id)
            ->assertSet('renewAlreadyPaidThisMonth', true);

        // sendRenewOtp is a no-op in this state.
        $second->call('sendRenewOtp')->assertSet('renewOtpSent', false);

        // Even a forced submit is rejected with a clear message, no 2nd row.
        $second->call('submitRenew')
            ->assertSet('renewError', fn ($m) => str_contains($m, 'sudah tercatat bayar'));

        $this->assertSame(1, CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->where('scheme', CommissionScheme::Titip->value)->count());
    }

    public function test_admin_opening_renew_defaults_to_the_next_unpaid_period_not_a_block(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer($this->packageWithTitipRate(3000));

        // September is already paid.
        $referrer = Referrer::factory()->create(['tenant_id' => $this->tenant->id]);
        CommissionLedger::factory()->create([
            'tenant_id' => $this->tenant->id,
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Titip->value,
            'status' => CommissionStatus::Eligible,
            'amount' => 3000,
            'payment_period' => now()->startOfMonth()->toDateString(),
        ]);

        Livewire::actingAs($admin)->test(CustomerIndex::class)
            ->call('openRenew', $customer->id)
            // Admin: tidak diblok — modal default ke periode belum-terbayar.
            ->assertSet('renewAlreadyPaidThisMonth', false)
            ->assertSet('renewStartPeriod', now()->startOfMonth()->addMonth()->format('Y-m'))
            ->call('submitRenew')
            ->assertSet('renewFlash', fn ($m) => str_contains($m, 'dicatat'));

        // Baris baru untuk BULAN DEPAN, September tidak diganggu.
        $this->assertSame(2, CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->where('scheme', CommissionScheme::Titip->value)->count());
    }

    public function test_admin_explicitly_picking_an_already_paid_period_is_hard_blocked(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer($this->packageWithTitipRate(3000));

        $paidPeriod = now()->startOfMonth();
        $referrer = Referrer::factory()->create(['tenant_id' => $this->tenant->id]);
        CommissionLedger::factory()->create([
            'tenant_id' => $this->tenant->id,
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Titip->value,
            'status' => CommissionStatus::Eligible,
            'amount' => 3000,
            'payment_period' => $paidPeriod->toDateString(),
        ]);

        Livewire::actingAs($admin)->test(CustomerIndex::class)
            ->call('openRenew', $customer->id)
            ->set('renewStartPeriod', $paidPeriod->format('Y-m'))
            ->set('renewMonths', 1)
            ->call('submitRenew')
            ->assertSet('renewError', fn ($m) => str_contains($m, 'sudah tercatat bayar'));

        $this->assertSame(1, CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->where('scheme', CommissionScheme::Titip->value)->count());
    }

    public function test_admin_multi_month_creates_one_row_per_consecutive_period(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer($this->packageWithTitipRate(3000, sellPrice: 200000));

        Livewire::actingAs($admin)->test(CustomerIndex::class)
            ->call('openRenew', $customer->id)
            ->set('renewMonths', 3)
            ->set('renewStartPeriod', now()->startOfMonth()->format('Y-m'))
            ->call('submitRenew')
            ->assertSet('renewFlash', fn ($m) => str_contains($m, '3 bulan'));

        $rows = CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->where('scheme', CommissionScheme::Titip->value)
            ->orderBy('payment_period')->get();

        $this->assertCount(3, $rows);
        $expected = collect([0, 1, 2])->map(fn ($i) => now()->startOfMonth()->addMonths($i)->toDateString());
        $this->assertSame($expected->all(), $rows->pluck('payment_period')->map(fn ($p) => $p->toDateString())->all());
        // gross_amount sama tiap baris (bukan dikali/dibagi).
        $rows->each(fn ($r) => $this->assertSame('200000.00', $r->gross_amount));

        // Satu entri timeline saja untuk 3 bulan.
        $this->assertSame(1, CustomerTimelineEntry::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->where('event_type', 'subscription_renewed')->count());
    }

    public function test_admin_multi_month_with_one_conflicting_period_rejects_the_whole_transaction(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer($this->packageWithTitipRate(3000));

        // Bulan ke-2 dari rentang sudah dibayar.
        $conflict = now()->startOfMonth()->addMonth();
        $referrer = Referrer::factory()->create(['tenant_id' => $this->tenant->id]);
        CommissionLedger::factory()->create([
            'tenant_id' => $this->tenant->id,
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Titip->value,
            'status' => CommissionStatus::Eligible,
            'amount' => 3000,
            'payment_period' => $conflict->toDateString(),
        ]);

        Livewire::actingAs($admin)->test(CustomerIndex::class)
            ->call('openRenew', $customer->id)
            ->set('renewMonths', 3)
            ->set('renewStartPeriod', now()->startOfMonth()->format('Y-m'))
            ->call('submitRenew')
            ->assertSet('renewError', fn ($m) => str_contains($m, $conflict->translatedFormat('F Y')));

        // Cuma baris konflik yang ada — tidak ada baris baru sama sekali.
        $this->assertSame(1, CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->where('scheme', CommissionScheme::Titip->value)->count());
    }

    public function test_referrer_self_service_has_no_multi_month_fields(): void
    {
        $referrer = $this->referrerUser(ReferrerType::Sales);
        $customer = $this->customer($this->packageWithTitipRate(3000));

        $html = Livewire::actingAs($referrer->user)->test(CustomerIndex::class)
            ->call('openRenew', $customer->id)
            ->html();

        $this->assertStringNotContainsString('Jumlah Bulan', $html);
        $this->assertStringNotContainsString('Mulai dari Periode', $html);
        $this->assertStringNotContainsString('renewMonths', $html);
    }

    public function test_admin_multi_month_referrer_gets_commission_per_month(): void
    {
        // Admin user yang JUGA Referrer Sales (skenario v0.22 "staff + referral").
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'customers.view', 'guard_name' => 'web']));
        $referrer = Referrer::factory()->create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'type' => ReferrerType::Sales, 'phone' => '081299900011', 'is_active' => true,
        ]);
        $customer = $this->customer($this->packageWithTitipRate(3000));

        $scope = "renewal:{$customer->id}";

        Livewire::actingAs($user)->test(CustomerIndex::class)
            ->call('openRenew', $customer->id)
            ->set('renewMonths', 3)
            ->set('renewStartPeriod', now()->startOfMonth()->format('Y-m'))
            ->call('sendRenewOtp')
            ->set('renewOtp', Cache::get("referrer-otp:{$referrer->id}:{$scope}")['code'])
            ->call('verifyRenewOtp')
            ->call('submitRenew')
            ->assertSet('renewFlash', fn ($m) => str_contains($m, 'komisi Titip Rp 9.000')); // 3000 x 3

        $rows = CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->where('scheme', CommissionScheme::Titip->value)->get();
        $this->assertCount(3, $rows);
        $rows->each(fn ($r) => $this->assertSame('3000.00', $r->amount));
    }
}
