<?php

namespace Tests\Feature\Customers;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Enums\NetworkProfileGroupType;
use App\Enums\ReferrerType;
use App\Enums\WhatsappEventType;
use App\Livewire\Customers\CustomerIndex;
use App\Models\BandwidthProfile;
use App\Models\CommissionLedger;
use App\Models\CommissionRate;
use App\Models\Customer;
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

    private function packageWithTitipRate(?float $titip, string $name = 'Paket Uji'): PppPackage
    {
        $group = NetworkProfileGroup::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => NetworkProfileGroupType::Ppp,
        ]);

        $package = PppPackage::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'is_active' => true,
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

    public function test_renew_by_a_teknisi_referrer_records_the_renewal_but_no_commission(): void
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

        $this->assertDatabaseMissing('commission_ledger', [
            'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Titip->value,
        ]);
        $this->assertDatabaseHas('customer_timeline_entries', [
            'customer_id' => $customer->id,
            'event_type' => 'subscription_renewed',
        ]);
    }

    public function test_admin_without_a_linked_referrer_renews_with_no_otp_and_no_commission(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer($this->packageWithTitipRate(3000));

        Livewire::actingAs($admin)->test(CustomerIndex::class)
            ->call('openRenew', $customer->id)
            ->call('submitRenew')
            ->assertSet('renewModalOpen', false)
            ->assertSet('renewFlash', fn ($m) => str_contains($m, 'dicatat'));

        $this->assertDatabaseMissing('commission_ledger', [
            'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Titip->value,
        ]);
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
}
