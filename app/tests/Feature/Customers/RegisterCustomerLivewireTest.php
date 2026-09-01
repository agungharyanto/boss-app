<?php

namespace Tests\Feature\Customers;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Enums\NetworkProfileGroupType;
use App\Livewire\Customers\RegisterCustomer;
use App\Models\BandwidthProfile;
use App\Models\CommissionRate;
use App\Models\Customer;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\Referrer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RegisterCustomerLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function package(int $tenantId): PppPackage
    {
        $group = NetworkProfileGroup::factory()->create([
            'tenant_id' => $tenantId,
            'type' => NetworkProfileGroupType::Ppp,
        ]);

        return PppPackage::factory()->create([
            'tenant_id' => $tenantId,
            'network_profile_group_id' => $group->id,
            'bandwidth_profile_id' => BandwidthProfile::factory()->create(['tenant_id' => $tenantId])->id,
        ]);
    }

    public function test_duplicate_nik_is_rejected_via_the_livewire_registration_form(): void
    {
        $user = $this->userWithRole('sales_internal');

        Customer::factory()->create([
            'tenant_id' => $user->tenant_id,
            'nik' => '3201012501990001',
        ]);

        $this->actingAs($user);

        Livewire::test(RegisterCustomer::class)
            ->set('name', 'Nama Lain')
            ->set('address', 'Jl. Merdeka No. 2')
            ->set('phone_number', '081234567899')
            ->set('nik', '3201012501990001')
            ->call('register')
            ->assertHasErrors(['nik']);

        $this->assertDatabaseCount('customers', 1);
    }

    public function test_a_fresh_nik_is_accepted_via_the_livewire_registration_form(): void
    {
        $user = $this->userWithRole('sales_internal');

        $this->actingAs($user);

        Livewire::test(RegisterCustomer::class)
            ->set('name', 'Pelanggan Baru')
            ->set('address', 'Jl. Merdeka No. 3')
            ->set('phone_number', '081234567898')
            ->set('nik', '3201012501990002')
            ->call('register')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', ['name' => 'Pelanggan Baru']);
    }

    public function test_scheme_dropdown_only_offers_options_available_on_the_rate(): void
    {
        $user = $this->userWithRole('superadmin');
        $referrer = Referrer::factory()->create(['tenant_id' => $user->tenant_id]);
        $package = $this->package($user->tenant_id);
        // Rate hanya punya recurring_amount — 'X-Kali' TIDAK boleh muncul.
        CommissionRate::factory()->create([
            'ppp_package_id' => $package->id,
            'recurring_amount' => 15000,
            'limited_count_amount' => null,
            'limited_count_times' => null,
        ]);

        $this->actingAs($user);

        Livewire::test(RegisterCustomer::class)
            ->set('ppp_package_id', $package->id)
            ->set('selectedReferrerId', $referrer->id)
            ->assertViewHas('showSchemeField', true)
            ->assertViewHas('schemeOptions', ['recurring' => 'Per Bulan']);
    }

    public function test_scheme_field_hidden_without_a_referrer_or_a_rate(): void
    {
        $user = $this->userWithRole('superadmin');
        $package = $this->package($user->tenant_id);
        CommissionRate::factory()->create(['ppp_package_id' => $package->id, 'recurring_amount' => 15000]);

        $this->actingAs($user);

        // Paket dipilih, rate ada, TAPI belum ada referrer → field tidak muncul.
        Livewire::test(RegisterCustomer::class)
            ->set('ppp_package_id', $package->id)
            ->assertViewHas('showSchemeField', false);
    }

    public function test_registering_with_referrer_package_and_scheme_creates_ledger_with_amount(): void
    {
        $user = $this->userWithRole('superadmin');
        $referrer = Referrer::factory()->create(['tenant_id' => $user->tenant_id]);
        $package = $this->package($user->tenant_id);
        CommissionRate::factory()->create([
            'ppp_package_id' => $package->id,
            'limited_count_amount' => 30000,
            'limited_count_times' => 2,
            'recurring_amount' => null,
        ]);

        $this->actingAs($user);

        Livewire::test(RegisterCustomer::class)
            ->set('name', 'Pelanggan Skema')
            ->set('address', 'Jl. Skema No. 1')
            ->set('phone_number', '081200000001')
            ->set('ppp_package_id', $package->id)
            ->set('selectedReferrerId', $referrer->id)
            ->set('commissionScheme', CommissionScheme::LimitedCount->value)
            ->call('register')
            ->assertHasNoErrors();

        $customer = Customer::where('name', 'Pelanggan Skema')->firstOrFail();
        $this->assertSame($package->id, $customer->ppp_package_id);
        $this->assertDatabaseHas('commission_ledger', [
            'customer_id' => $customer->id,
            'referrer_id' => $referrer->id,
            'scheme' => CommissionScheme::LimitedCount->value,
            'amount' => '30000.00',
            'status' => CommissionStatus::Pending->value,
        ]);
    }

    public function test_registering_with_referrer_but_no_scheme_keeps_amount_null(): void
    {
        $user = $this->userWithRole('superadmin');
        $referrer = Referrer::factory()->create(['tenant_id' => $user->tenant_id]);
        $package = $this->package($user->tenant_id);
        CommissionRate::factory()->create(['ppp_package_id' => $package->id, 'recurring_amount' => 15000]);

        $this->actingAs($user);

        Livewire::test(RegisterCustomer::class)
            ->set('name', 'Pelanggan Skip Skema')
            ->set('address', 'Jl. Skema No. 2')
            ->set('phone_number', '081200000002')
            ->set('ppp_package_id', $package->id)
            ->set('selectedReferrerId', $referrer->id)
            // commissionScheme sengaja tidak di-set
            ->call('register')
            ->assertHasNoErrors();

        $customer = Customer::where('name', 'Pelanggan Skip Skema')->firstOrFail();
        $this->assertDatabaseHas('commission_ledger', [
            'customer_id' => $customer->id,
            'scheme' => null,
            'amount' => null,
        ]);
    }
}
