<?php

namespace Tests\Feature\Network;

use App\Enums\NetworkProfileGroupType;
use App\Livewire\Network\PppPackageIndex;
use App\Models\BandwidthProfile;
use App\Models\CustomerIpPool;
use App\Models\HotspotPackage;
use App\Models\Nas;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PppPackageIndexLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('superadmin');

        return $user;
    }

    /**
     * @return array{tenant: Tenant, nas: Nas, group: NetworkProfileGroup, bandwidth: BandwidthProfile}
     */
    private function fixtures(): array
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create([
            'nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id, 'type' => NetworkProfileGroupType::Ppp,
        ]);
        $bandwidth = BandwidthProfile::factory()->create(['tenant_id' => $tenant->id]);

        return compact('tenant', 'nas', 'group', 'bandwidth');
    }

    public function test_creating_a_package_via_the_form(): void
    {
        $f = $this->fixtures();

        Livewire::actingAs($this->admin($f['tenant']))
            ->test(PppPackageIndex::class)
            ->set('networkProfileGroupId', (string) $f['group']->id)
            ->set('bandwidthProfileId', (string) $f['bandwidth']->id)
            ->set('name', 'Paket Bulanan Baru')
            ->set('costPrice', '50000')
            ->set('sellPrice', '100000')
            ->set('activeDurationValue', '1')
            ->set('activeDurationUnit', 'month')
            ->call('createPackage')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('ppp_packages', ['network_profile_group_id' => $f['group']->id, 'name' => 'Paket Bulanan Baru']);
    }

    public function test_submitting_without_selecting_a_group_is_rejected(): void
    {
        $f = $this->fixtures();

        $component = Livewire::actingAs($this->admin($f['tenant']))
            ->test(PppPackageIndex::class)
            ->set('bandwidthProfileId', (string) $f['bandwidth']->id)
            ->set('name', 'Paket Tanpa Grup')
            ->set('costPrice', '50000')
            ->set('sellPrice', '100000')
            ->call('createPackage');

        $component->assertHasErrors('networkProfileGroupId');
        $this->assertDatabaseMissing('ppp_packages', ['name' => 'Paket Tanpa Grup']);
    }

    public function test_simpan_button_is_disabled_until_a_group_is_selected(): void
    {
        $f = $this->fixtures();

        $hasDisabledAttribute = fn (string $buttonHtml): bool => (bool) preg_match('/\bdisabled\b(?!:)/', $buttonHtml);

        $component = Livewire::actingAs($this->admin($f['tenant']))
            ->test(PppPackageIndex::class)
            ->set('showCreateForm', true);

        preg_match('/<button type="submit"[^>]*>/', $component->html(), $before);
        $this->assertNotEmpty($before, 'Simpan button not found in rendered HTML');
        $this->assertTrue($hasDisabledAttribute($before[0]));

        $htmlAfter = $component->set('networkProfileGroupId', (string) $f['group']->id)->html();
        preg_match('/<button type="submit"[^>]*>/', $htmlAfter, $after);
        $this->assertNotEmpty($after, 'Simpan button not found in rendered HTML');
        $this->assertFalse($hasDisabledAttribute($after[0]));
    }

    public function test_a_hotspot_type_group_is_rejected_for_a_ppp_package(): void
    {
        $f = $this->fixtures();
        $hotspotPool = CustomerIpPool::factory()->create(['nas_id' => $f['nas']->id]);
        $hotspotGroup = NetworkProfileGroup::factory()->create([
            'nas_id' => $f['nas']->id, 'customer_ip_pool_id' => $hotspotPool->id, 'type' => NetworkProfileGroupType::Hotspot,
        ]);

        $component = Livewire::actingAs($this->admin($f['tenant']))
            ->test(PppPackageIndex::class)
            ->set('networkProfileGroupId', (string) $hotspotGroup->id)
            ->set('bandwidthProfileId', (string) $f['bandwidth']->id)
            ->set('name', 'Paket Salah Tipe')
            ->set('costPrice', '50000')
            ->set('sellPrice', '100000')
            ->call('createPackage');

        $component->assertHasErrors('networkProfileGroupId');
    }

    /**
     * Aturan nama final (2026-09-05): dunia PPP bebas senama — nama Profil
     * PPP BOLEH sama dengan Grup Profil ppp induknya. Collision /ppp
     * profile di router di-handle otomatis (PppPackage::routerOsProfileName()).
     */
    public function test_a_name_matching_the_parent_ppp_grup_profil_is_now_allowed(): void
    {
        $f = $this->fixtures();

        $component = Livewire::actingAs($this->admin($f['tenant']))
            ->test(PppPackageIndex::class)
            ->set('networkProfileGroupId', (string) $f['group']->id)
            ->set('bandwidthProfileId', (string) $f['bandwidth']->id)
            ->set('name', $f['group']->name)
            ->set('costPrice', '50000')
            ->set('sellPrice', '100000')
            ->set('activeDurationValue', '1')
            ->set('activeDurationUnit', 'month')
            ->call('createPackage');

        $component->assertHasNoErrors();
        $this->assertDatabaseHas('ppp_packages', ['network_profile_group_id' => $f['group']->id, 'name' => $f['group']->name]);
    }

    public function test_a_name_matching_a_hotspot_grup_profil_on_the_same_nas_is_rejected(): void
    {
        $f = $this->fixtures();
        $hotspotPool = CustomerIpPool::factory()->create(['nas_id' => $f['nas']->id]);
        NetworkProfileGroup::factory()->create([
            'nas_id' => $f['nas']->id, 'customer_ip_pool_id' => $hotspotPool->id,
            'type' => NetworkProfileGroupType::Hotspot, 'name' => 'TOKEN-Harian',
        ]);

        $component = Livewire::actingAs($this->admin($f['tenant']))
            ->test(PppPackageIndex::class)
            ->set('networkProfileGroupId', (string) $f['group']->id)
            ->set('bandwidthProfileId', (string) $f['bandwidth']->id)
            ->set('name', 'TOKEN-Harian')
            ->set('costPrice', '50000')
            ->set('sellPrice', '100000')
            ->set('activeDurationValue', '1')
            ->set('activeDurationUnit', 'month')
            ->call('createPackage');

        $component->assertHasErrors('name');
        $this->assertDatabaseMissing('ppp_packages', ['name' => 'TOKEN-Harian']);
    }

    public function test_a_name_matching_a_hotspot_package_on_the_same_nas_is_rejected(): void
    {
        $f = $this->fixtures();
        $hotspotPool = CustomerIpPool::factory()->create(['nas_id' => $f['nas']->id]);
        $hotspotGroup = NetworkProfileGroup::factory()->create([
            'nas_id' => $f['nas']->id, 'customer_ip_pool_id' => $hotspotPool->id, 'type' => NetworkProfileGroupType::Hotspot,
        ]);
        HotspotPackage::factory()->create([
            'network_profile_group_id' => $hotspotGroup->id, 'name' => 'Voucher-6Jam',
        ]);

        $component = Livewire::actingAs($this->admin($f['tenant']))
            ->test(PppPackageIndex::class)
            ->set('networkProfileGroupId', (string) $f['group']->id)
            ->set('bandwidthProfileId', (string) $f['bandwidth']->id)
            ->set('name', 'Voucher-6Jam')
            ->set('costPrice', '50000')
            ->set('sellPrice', '100000')
            ->set('activeDurationValue', '1')
            ->set('activeDurationUnit', 'month')
            ->call('createPackage');

        $component->assertHasErrors('name');
    }

    public function test_editing_a_package_does_not_flag_a_collision_with_its_own_unchanged_name(): void
    {
        $f = $this->fixtures();
        $package = PppPackage::factory()->create(['network_profile_group_id' => $f['group']->id, 'name' => 'Paket-Existing']);

        Livewire::actingAs($this->admin($f['tenant']))
            ->test(PppPackageIndex::class)
            ->call('edit', $package->id)
            ->set('editCostPrice', '60000')
            ->set('editSellPrice', '120000')
            ->call('updatePackage')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('ppp_packages', ['id' => $package->id, 'cost_price' => 60000, 'name' => 'Paket-Existing']);
    }

    public function test_deleting_a_package_soft_deletes_it(): void
    {
        $f = $this->fixtures();
        $package = PppPackage::factory()->create(['network_profile_group_id' => $f['group']->id]);

        Livewire::actingAs($this->admin($f['tenant']))
            ->test(PppPackageIndex::class)
            ->call('deletePackage', $package->id);

        $this->assertSoftDeleted('ppp_packages', ['id' => $package->id]);
    }

    public function test_non_admin_tier_user_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($user)
            ->test(PppPackageIndex::class)
            ->assertForbidden();
    }

    // --- Revisi Prioritas Dropdown ----------------------------------------

    public function test_priority_dropdown_only_offers_values_1_through_8(): void
    {
        $f = $this->fixtures();

        $html = Livewire::actingAs($this->admin($f['tenant']))
            ->test(PppPackageIndex::class)
            ->set('showCreateForm', true)
            ->html();

        preg_match('/wire:model="priority".*?<\/select>/s', $html, $matches);
        $this->assertNotEmpty($matches, 'Priority dropdown not found in rendered HTML');
        preg_match_all('/<option value="(\d+)"/', $matches[0], $optionValues);
        $this->assertSame(['1', '2', '3', '4', '5', '6', '7', '8'], $optionValues[1]);
    }

    public function test_priority_outside_1_to_8_is_rejected(): void
    {
        $f = $this->fixtures();

        $component = Livewire::actingAs($this->admin($f['tenant']))
            ->test(PppPackageIndex::class)
            ->set('networkProfileGroupId', (string) $f['group']->id)
            ->set('bandwidthProfileId', (string) $f['bandwidth']->id)
            ->set('name', 'Paket Prioritas Salah')
            ->set('costPrice', '50000')
            ->set('sellPrice', '100000')
            ->set('priority', '0')
            ->call('createPackage');

        $component->assertHasErrors('priority');
        $this->assertDatabaseMissing('ppp_packages', ['name' => 'Paket Prioritas Salah']);
    }

    public function test_creating_a_package_with_a_valid_priority_stores_it_as_integer(): void
    {
        $f = $this->fixtures();

        Livewire::actingAs($this->admin($f['tenant']))
            ->test(PppPackageIndex::class)
            ->set('networkProfileGroupId', (string) $f['group']->id)
            ->set('bandwidthProfileId', (string) $f['bandwidth']->id)
            ->set('name', 'Paket Prioritas 3')
            ->set('costPrice', '50000')
            ->set('sellPrice', '100000')
            ->set('priority', '3')
            ->call('createPackage')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('ppp_packages', ['name' => 'Paket Prioritas 3', 'priority' => 3]);
    }
}
