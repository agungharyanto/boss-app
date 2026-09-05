<?php

namespace Tests\Feature\Commission;

use App\Enums\NetworkProfileGroupType;
use App\Livewire\Commission\CommissionRateIndex;
use App\Models\BandwidthProfile;
use App\Models\CommissionRate;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CommissionRateIndexLivewireTest extends TestCase
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

    private function package(Tenant $tenant, string $name = 'Paket X'): PppPackage
    {
        $group = NetworkProfileGroup::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => NetworkProfileGroupType::Ppp,
        ]);

        return PppPackage::factory()->create([
            'tenant_id' => $tenant->id,
            'network_profile_group_id' => $group->id,
            'bandwidth_profile_id' => BandwidthProfile::factory()->create(['tenant_id' => $tenant->id])->id,
            'name' => $name,
        ]);
    }

    public function test_page_lists_every_ppp_package_including_ones_without_a_rate(): void
    {
        $tenant = Tenant::factory()->create();
        $withRate = $this->package($tenant, 'Punya Rate');
        CommissionRate::factory()->create(['ppp_package_id' => $withRate->id, 'recurring_amount' => 30000]);
        $this->package($tenant, 'Belum Ada Rate');

        Livewire::actingAs($this->admin($tenant))
            ->test(CommissionRateIndex::class)
            ->assertSee('Punya Rate')
            ->assertSee('Belum Ada Rate')
            ->assertSee('Belum diatur');
    }

    public function test_soft_deleted_package_is_not_listed(): void
    {
        $tenant = Tenant::factory()->create();
        $gone = $this->package($tenant, 'Sudah Dihapus');
        $gone->delete();

        Livewire::actingAs($this->admin($tenant))
            ->test(CommissionRateIndex::class)
            ->assertDontSee('Sudah Dihapus');
    }

    public function test_admin_can_set_a_recurring_rate_for_a_package(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);

        Livewire::actingAs($this->admin($tenant))
            ->test(CommissionRateIndex::class)
            ->call('edit', $package->id)
            ->set('recurringAmount', '27500')
            ->call('saveRate')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('commission_rates', [
            'ppp_package_id' => $package->id,
            'tenant_id' => $tenant->id,
            'recurring_amount' => 27500,
        ]);
    }

    public function test_edit_prefills_an_existing_rate(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);
        CommissionRate::factory()->create([
            'ppp_package_id' => $package->id,
            'recurring_amount' => 12345,
            'limited_count_amount' => 6000,
            'limited_count_times' => 4,
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CommissionRateIndex::class)
            ->call('edit', $package->id)
            ->assertSet('recurringAmount', '12345.00')
            ->assertSet('limitedCountAmount', '6000.00')
            ->assertSet('limitedCountTimes', '4');
    }

    public function test_saving_an_existing_rate_updates_it_in_place(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);
        $rate = CommissionRate::factory()->create(['ppp_package_id' => $package->id, 'recurring_amount' => 1000]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CommissionRateIndex::class)
            ->call('edit', $package->id)
            ->set('recurringAmount', '2000')
            ->call('saveRate')
            ->assertHasNoErrors();

        $this->assertSame(1, CommissionRate::where('ppp_package_id', $package->id)->count());
        $this->assertEquals(2000, $rate->fresh()->recurring_amount);
    }

    public function test_limited_count_pair_must_be_filled_together(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);

        Livewire::actingAs($this->admin($tenant))
            ->test(CommissionRateIndex::class)
            ->call('edit', $package->id)
            ->set('limitedCountAmount', '5000')
            ->call('saveRate')
            ->assertHasErrors('limitedCountTimes');

        $this->assertDatabaseCount('commission_rates', 0);
    }

    public function test_at_least_one_scheme_must_be_filled(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);

        Livewire::actingAs($this->admin($tenant))
            ->test(CommissionRateIndex::class)
            ->call('edit', $package->id)
            ->call('saveRate')
            ->assertHasErrors('recurringAmount');
    }

    public function test_negative_amount_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);

        Livewire::actingAs($this->admin($tenant))
            ->test(CommissionRateIndex::class)
            ->call('edit', $package->id)
            ->set('titipAmount', '-5')
            ->call('saveRate')
            ->assertHasErrors('titipAmount');
    }

    public function test_admin_can_set_a_payout_window_on_a_rate(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);

        Livewire::actingAs($this->admin($tenant))
            ->test(CommissionRateIndex::class)
            ->call('edit', $package->id)
            ->set('recurringAmount', '5000')
            ->set('payoutWindowStartDay', '5')
            ->set('payoutWindowEndDay', '7')
            ->call('saveRate')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('commission_rates', [
            'ppp_package_id' => $package->id,
            'payout_window_start_day' => 5,
            'payout_window_end_day' => 7,
        ]);
    }

    public function test_leaving_both_payout_window_fields_empty_means_payable_anytime(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);

        Livewire::actingAs($this->admin($tenant))
            ->test(CommissionRateIndex::class)
            ->call('edit', $package->id)
            ->set('recurringAmount', '5000')
            ->call('saveRate')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('commission_rates', [
            'ppp_package_id' => $package->id,
            'payout_window_start_day' => null,
            'payout_window_end_day' => null,
        ]);
    }

    public function test_payout_window_fields_must_be_filled_together(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);

        Livewire::actingAs($this->admin($tenant))
            ->test(CommissionRateIndex::class)
            ->call('edit', $package->id)
            ->set('recurringAmount', '5000')
            ->set('payoutWindowStartDay', '5')
            ->call('saveRate')
            ->assertHasErrors('payoutWindowEndDay');

        $this->assertDatabaseCount('commission_rates', 0);
    }

    public function test_payout_window_end_before_start_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);

        Livewire::actingAs($this->admin($tenant))
            ->test(CommissionRateIndex::class)
            ->call('edit', $package->id)
            ->set('recurringAmount', '5000')
            ->set('payoutWindowStartDay', '20')
            ->set('payoutWindowEndDay', '10')
            ->call('saveRate')
            ->assertHasErrors('payoutWindowEndDay');

        $this->assertDatabaseCount('commission_rates', 0);
    }

    public function test_delete_rate_soft_deletes_it(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);
        $rate = CommissionRate::factory()->create(['ppp_package_id' => $package->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CommissionRateIndex::class)
            ->call('deleteRate', $package->id);

        $this->assertSoftDeleted('commission_rates', ['id' => $rate->id]);
    }

    public function test_a_user_without_the_permission_cannot_open_the_page(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant, 'customer_service'))
            ->test(CommissionRateIndex::class)
            ->assertForbidden();
    }

    public function test_administrator_tier_role_can_open_and_manage(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);

        Livewire::actingAs($this->admin($tenant, 'administrator'))
            ->test(CommissionRateIndex::class)
            ->call('edit', $package->id)
            ->set('recurringAmount', '1000')
            ->call('saveRate')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('commission_rates', ['ppp_package_id' => $package->id]);
    }
}
