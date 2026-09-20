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
     * Opsi (c) — referrer TERKUNCI setelah terisi: perubahan editReferrerId
     * dari client diabaikan total di server. Referrer lama dipertahankan,
     * tidak ada commission_ledger yang dibuat/diubah.
     */
    public function test_an_existing_referrer_is_locked_and_cannot_be_changed(): void
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
            ->assertViewHas('referrerLocked', true)
            ->call('startEditingCommission')
            ->set('editReferrerId', $newReferrer->id)
            ->call('updateCommissionAttribution')
            ->assertHasNoErrors();

        // Referrer TIDAK berubah — tetap yang lama.
        $this->assertSame($oldReferrer->id, $customer->fresh()->referred_by_referrer_id);
        $this->assertSame(0, CommissionLedger::where('customer_id', $customer->id)->count());
    }

    public function test_a_locked_referrer_cannot_be_cleared_but_the_package_can_still_change(): void
    {
        $user = $this->admin();
        $referrer = Referrer::factory()->create(['tenant_id' => $user->tenant_id]);
        $package = $this->package($user->tenant_id);
        $customer = Customer::factory()->create([
            'tenant_id' => $user->tenant_id,
            'referred_by_referrer_id' => $referrer->id,
        ]);

        $this->actingAs($user);

        Livewire::test(CustomerShow::class, ['customer' => $customer])
            ->call('startEditingCommission')
            ->set('editReferrerId', null)
            ->set('editPppPackageId', $package->id)
            ->call('updateCommissionAttribution')
            ->assertHasNoErrors();

        $fresh = $customer->fresh();
        $this->assertSame($referrer->id, $fresh->referred_by_referrer_id); // tetap terkunci
        $this->assertSame($package->id, $fresh->ppp_package_id);            // paket berubah
        $this->assertSame(0, CommissionLedger::where('customer_id', $customer->id)->count());
    }

    /**
     * v0.22.7 — referrerLocked() JUGA true kalau referral_locked, walau
     * referred_by_referrer_id sendiri sudah NULL (lihat StaffService::
     * lockCustomerReferralsForDeletedStaff()) — bukan cuma kondisi lama
     * "referred_by_referrer_id terisi".
     */
    public function test_referrer_locked_is_true_for_a_referral_locked_customer_even_with_no_referrer_id(): void
    {
        $user = $this->admin();
        $customer = Customer::factory()->create([
            'tenant_id' => $user->tenant_id,
            'referred_by_referrer_id' => null,
            'referral_locked' => true,
            'locked_former_referrer_name' => 'Mantan Referrer',
        ]);

        $this->actingAs($user);

        $component = Livewire::test(CustomerShow::class, ['customer' => $customer]);

        $this->assertTrue($component->instance()->referrerLocked());
    }

    /**
     * v0.22.7 — updateCommissionAttribution() menolak eksplisit (addError,
     * bukan diam-diam diabaikan) kalau referral_locked, meski payload
     * client memaksa editReferrerId terisi. Paket tetap boleh diubah.
     */
    public function test_update_commission_attribution_rejects_setting_a_referrer_for_a_referral_locked_customer(): void
    {
        $user = $this->admin();
        $package = $this->package($user->tenant_id);
        $newReferrer = Referrer::factory()->create(['tenant_id' => $user->tenant_id]);
        $customer = Customer::factory()->create([
            'tenant_id' => $user->tenant_id,
            'referred_by_referrer_id' => null,
            'referral_locked' => true,
            'locked_former_referrer_name' => 'Mantan Referrer',
        ]);

        $this->actingAs($user);

        Livewire::test(CustomerShow::class, ['customer' => $customer])
            ->call('startEditingCommission')
            ->set('editReferrerId', $newReferrer->id)
            ->set('editPppPackageId', $package->id)
            ->call('updateCommissionAttribution')
            ->assertHasErrors(['editReferrerId']);

        $fresh = $customer->fresh();
        $this->assertNull($fresh->referred_by_referrer_id);
        // Paket TIDAK berubah — method return awal (sebelum bagian yang
        // memproses editPppPackageId) begitu guard referral_locked kena.
        $this->assertNull($fresh->ppp_package_id);
        $this->assertSame(0, CommissionLedger::where('customer_id', $customer->id)->count());
    }

    /**
     * v0.22.7 — tampilan panel "Paket & Referral" (mode readonly DAN mode
     * edit) menunjukkan pesan locked yang benar untuk customer
     * referral_locked.
     */
    public function test_customer_show_page_renders_the_locked_referral_message(): void
    {
        $user = $this->admin();
        $customer = Customer::factory()->create([
            'tenant_id' => $user->tenant_id,
            'referred_by_referrer_id' => null,
            'referral_locked' => true,
            'locked_former_referrer_name' => 'Kamisem',
        ]);

        $this->actingAs($user);

        // Mode readonly (default).
        Livewire::test(CustomerShow::class, ['customer' => $customer])
            ->assertSee('sebelumnya oleh Kamisem')
            ->assertSee('terkunci karena staff resign');

        // Mode edit — field disabled, pesan yang sama tampil.
        Livewire::test(CustomerShow::class, ['customer' => $customer])
            ->call('startEditingCommission')
            ->assertSee('(kosong) — sebelumnya oleh Kamisem')
            ->assertSee('Referral terkunci karena staff resign');
    }
}
