<?php

namespace Tests\Feature\Customers;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Enums\NetworkProfileGroupType;
use App\Livewire\Customers\CustomerShow;
use App\Models\BandwidthProfile;
use App\Models\CommissionLedger;
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

class CustomerShowCommissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('superadmin');

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

    public function test_setting_a_referrer_on_a_customer_that_had_none_creates_a_pending_ledger(): void
    {
        $user = $this->admin();
        $customer = Customer::factory()->create([
            'tenant_id' => $user->tenant_id,
            'referred_by_referrer_id' => null,
        ]);
        $referrer = Referrer::factory()->create(['tenant_id' => $user->tenant_id]);

        $this->actingAs($user);

        Livewire::test(CustomerShow::class, ['customer' => $customer])
            ->call('startEditingCommission')
            ->set('editReferrerId', $referrer->id)
            ->call('updateCommissionAttribution')
            ->assertHasNoErrors();

        $this->assertSame($referrer->id, $customer->fresh()->referred_by_referrer_id);
        $this->assertDatabaseHas('commission_ledger', [
            'customer_id' => $customer->id,
            'referrer_id' => $referrer->id,
            'status' => CommissionStatus::Pending->value,
            'amount' => null,
            'scheme' => null,
        ]);
    }

    public function test_setting_referrer_package_and_scheme_fills_the_ledger_amount(): void
    {
        $user = $this->admin();
        $customer = Customer::factory()->create([
            'tenant_id' => $user->tenant_id,
            'referred_by_referrer_id' => null,
        ]);
        $referrer = Referrer::factory()->create(['tenant_id' => $user->tenant_id]);
        $package = $this->package($user->tenant_id);
        CommissionRate::factory()->create([
            'ppp_package_id' => $package->id,
            'recurring_amount' => 22000,
            'limited_count_amount' => null,
            'limited_count_times' => null,
        ]);

        $this->actingAs($user);

        Livewire::test(CustomerShow::class, ['customer' => $customer])
            ->call('startEditingCommission')
            ->set('editPppPackageId', $package->id)
            ->set('editReferrerId', $referrer->id)
            ->set('editCommissionScheme', CommissionScheme::Recurring->value)
            ->call('updateCommissionAttribution')
            ->assertHasNoErrors();

        $this->assertSame($package->id, $customer->fresh()->ppp_package_id);
        $this->assertDatabaseHas('commission_ledger', [
            'customer_id' => $customer->id,
            'referrer_id' => $referrer->id,
            'scheme' => CommissionScheme::Recurring->value,
            'amount' => '22000.00',
        ]);
    }

    /**
     * Aturan konservatif v0.9.4: pelanggan yang SUDAH punya referrer, lalu
     * referrer/paket-nya diganti → HANYA kolom customers yang di-update,
     * TIDAK ada baris commission_ledger baru (belum ada aturan yang jelas).
     */
    public function test_changing_an_existing_referrer_does_not_create_a_new_ledger(): void
    {
        $user = $this->admin();
        $oldReferrer = Referrer::factory()->create(['tenant_id' => $user->tenant_id]);
        $newReferrer = Referrer::factory()->create(['tenant_id' => $user->tenant_id]);
        $customer = Customer::factory()->create([
            'tenant_id' => $user->tenant_id,
            'referred_by_referrer_id' => $oldReferrer->id,
        ]);

        $this->actingAs($user);

        Livewire::test(CustomerShow::class, ['customer' => $customer])
            ->call('startEditingCommission')
            ->set('editReferrerId', $newReferrer->id)
            ->call('updateCommissionAttribution')
            ->assertHasNoErrors();

        $this->assertSame($newReferrer->id, $customer->fresh()->referred_by_referrer_id);
        $this->assertSame(0, CommissionLedger::where('customer_id', $customer->id)->count());
    }

    public function test_clearing_a_referrer_does_not_create_a_ledger(): void
    {
        $user = $this->admin();
        $referrer = Referrer::factory()->create(['tenant_id' => $user->tenant_id]);
        $customer = Customer::factory()->create([
            'tenant_id' => $user->tenant_id,
            'referred_by_referrer_id' => $referrer->id,
        ]);

        $this->actingAs($user);

        Livewire::test(CustomerShow::class, ['customer' => $customer])
            ->call('startEditingCommission')
            ->set('editReferrerId', null)
            ->call('updateCommissionAttribution')
            ->assertHasNoErrors();

        $this->assertNull($customer->fresh()->referred_by_referrer_id);
        $this->assertSame(0, CommissionLedger::where('customer_id', $customer->id)->count());
    }
}
