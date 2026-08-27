<?php

namespace Tests\Feature\Network;

use App\Enums\NetworkProfileGroupType;
use App\Livewire\Network\HotspotPackageIndex;
use App\Models\BandwidthProfile;
use App\Models\CustomerIpPool;
use App\Models\HotspotPackage;
use App\Models\Nas;
use App\Models\NetworkProfileGroup;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HotspotPackageIndexLivewireTest extends TestCase
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
            'nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id, 'type' => NetworkProfileGroupType::Hotspot,
        ]);
        $bandwidth = BandwidthProfile::factory()->create(['tenant_id' => $tenant->id]);

        return compact('tenant', 'nas', 'group', 'bandwidth');
    }

    public function test_creating_a_package_via_the_form(): void
    {
        $f = $this->fixtures();

        Livewire::actingAs($this->admin($f['tenant']))
            ->test(HotspotPackageIndex::class)
            ->set('networkProfileGroupId', (string) $f['group']->id)
            ->set('bandwidthProfileId', (string) $f['bandwidth']->id)
            ->set('name', 'Paket Baru')
            ->set('costPrice', '2000')
            ->set('sellPrice', '5000')
            ->call('createPackage')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('hotspot_packages', ['network_profile_group_id' => $f['group']->id, 'name' => 'Paket Baru']);
    }

    /**
     * v0.14.4 amendment — see CustomerIpPoolIndexLivewireTest's own
     * docblock for the full investigation (Agung's "NAS harus di atas
     * Simpan" report). Profil Hotspot has no NAS field of its own — Grup
     * Profil determines it implicitly — so this is the equivalent
     * required-field check for THIS form.
     */
    public function test_submitting_without_selecting_a_group_is_rejected(): void
    {
        $f = $this->fixtures();

        $component = Livewire::actingAs($this->admin($f['tenant']))
            ->test(HotspotPackageIndex::class)
            ->set('bandwidthProfileId', (string) $f['bandwidth']->id)
            ->set('name', 'Paket Tanpa Grup')
            ->set('costPrice', '2000')
            ->set('sellPrice', '5000')
            ->call('createPackage');

        $component->assertHasErrors('networkProfileGroupId');
        $this->assertDatabaseMissing('hotspot_packages', ['name' => 'Paket Tanpa Grup']);
    }

    public function test_simpan_button_is_disabled_until_a_group_is_selected(): void
    {
        $f = $this->fixtures();

        $hasDisabledAttribute = fn (string $buttonHtml): bool => (bool) preg_match('/\bdisabled\b(?!:)/', $buttonHtml);

        $component = Livewire::actingAs($this->admin($f['tenant']))
            ->test(HotspotPackageIndex::class)
            ->set('showCreateForm', true);

        preg_match('/<button type="submit"[^>]*>/', $component->html(), $before);
        $this->assertNotEmpty($before, 'Simpan button not found in rendered HTML');
        $this->assertTrue($hasDisabledAttribute($before[0]));

        $htmlAfterSelectingGroup = $component->set('networkProfileGroupId', (string) $f['group']->id)->html();
        preg_match('/<button type="submit"[^>]*>/', $htmlAfterSelectingGroup, $after);
        $this->assertNotEmpty($after, 'Simpan button not found in rendered HTML');
        $this->assertFalse($hasDisabledAttribute($after[0]));
    }

    /**
     * v0.14.4 — dropdown only ever queries type=hotspot groups at the
     * source (HotspotPackageIndex::render()'s own 'groupOptions' query) —
     * a PPP-type group should never even appear as an option.
     */
    public function test_group_dropdown_only_lists_hotspot_type_groups(): void
    {
        $f = $this->fixtures();
        NetworkProfileGroup::factory()->create([
            'nas_id' => $f['nas']->id,
            'customer_ip_pool_id' => CustomerIpPool::factory()->create(['nas_id' => $f['nas']->id])->id,
            'type' => NetworkProfileGroupType::Ppp,
            'name' => 'Grup PPP Saja',
        ]);

        $html = Livewire::actingAs($this->admin($f['tenant']))
            ->test(HotspotPackageIndex::class)
            ->set('showCreateForm', true)
            ->html();

        $this->assertStringContainsString($f['group']->name, $html);
        $this->assertStringNotContainsString('Grup PPP Saja', $html);
    }

    public function test_selecting_a_limited_profile_type_reveals_the_duration_fields(): void
    {
        $f = $this->fixtures();

        $html = Livewire::actingAs($this->admin($f['tenant']))
            ->test(HotspotPackageIndex::class)
            ->set('showCreateForm', true)
            ->set('profileType', 'limited')
            ->html();

        $this->assertStringContainsString('Masa Aktif', $html);
        $this->assertStringContainsString('Batasan', $html);
    }

    public function test_creating_a_limited_package_without_duration_fails_validation(): void
    {
        $f = $this->fixtures();

        $component = Livewire::actingAs($this->admin($f['tenant']))
            ->test(HotspotPackageIndex::class)
            ->set('networkProfileGroupId', (string) $f['group']->id)
            ->set('bandwidthProfileId', (string) $f['bandwidth']->id)
            ->set('name', 'Paket Limited')
            ->set('profileType', 'limited')
            ->call('createPackage');

        // activeDurationUnit is NOT expected here — its property default
        // ('day') already satisfies required_if on its own; only the two
        // fields with an empty-string default (limitType,
        // activeDurationValue) actually fail when left untouched.
        $component->assertHasErrors(['limitType', 'activeDurationValue']);
    }

    public function test_creating_a_limited_time_base_package_stores_duration_correctly(): void
    {
        $f = $this->fixtures();

        Livewire::actingAs($this->admin($f['tenant']))
            ->test(HotspotPackageIndex::class)
            ->set('networkProfileGroupId', (string) $f['group']->id)
            ->set('bandwidthProfileId', (string) $f['bandwidth']->id)
            ->set('name', 'Paket Limited')
            ->set('profileType', 'limited')
            ->set('limitType', 'time_base')
            ->set('activeDurationValue', '3')
            ->set('activeDurationUnit', 'hour')
            ->call('createPackage')
            ->assertHasNoErrors();

        $package = HotspotPackage::where('name', 'Paket Limited')->first();
        $this->assertSame('limited', $package->profile_type->value);
        $this->assertSame('time_base', $package->limit_type->value);
        $this->assertSame(3, $package->active_duration_value);
        $this->assertSame('hour', $package->active_duration_unit->value);
    }

    /**
     * v0.14.4 amendment — real gap confirmed by Agung via screenshot: the
     * form was missing Kuota/Satuan Data for QuotaBase entirely. Selecting
     * QuotaBase must reveal them, same show/hide pattern already
     * established for Batasan/Masa Aktif.
     */
    public function test_selecting_quota_base_reveals_the_quota_fields(): void
    {
        $f = $this->fixtures();

        $html = Livewire::actingAs($this->admin($f['tenant']))
            ->test(HotspotPackageIndex::class)
            ->set('showCreateForm', true)
            ->set('profileType', 'limited')
            ->set('limitType', 'quota_base')
            ->html();

        $this->assertStringContainsString('Kuota', $html);
        $this->assertStringContainsString('Satuan Data', $html);
    }

    public function test_selecting_time_base_does_not_show_the_quota_fields(): void
    {
        $f = $this->fixtures();

        $html = Livewire::actingAs($this->admin($f['tenant']))
            ->test(HotspotPackageIndex::class)
            ->set('showCreateForm', true)
            ->set('profileType', 'limited')
            ->set('limitType', 'time_base')
            ->html();

        $this->assertStringNotContainsString('Satuan Data', $html);
    }

    public function test_creating_a_quota_base_package_without_quota_fails_validation(): void
    {
        $f = $this->fixtures();

        $component = Livewire::actingAs($this->admin($f['tenant']))
            ->test(HotspotPackageIndex::class)
            ->set('networkProfileGroupId', (string) $f['group']->id)
            ->set('bandwidthProfileId', (string) $f['bandwidth']->id)
            ->set('name', 'Paket Kuota')
            ->set('profileType', 'limited')
            ->set('limitType', 'quota_base')
            ->set('activeDurationValue', '30')
            ->call('createPackage');

        // quotaUnit is NOT expected here — updatedLimitType() already
        // filled it in with a sensible 'mb' default the moment QuotaBase
        // was selected above; only quotaValue (genuinely never touched)
        // fails.
        $component->assertHasErrors(['quotaValue']);
        $component->assertHasNoErrors(['quotaUnit']);
    }

    public function test_creating_a_quota_base_package_with_full_data_stores_correctly(): void
    {
        $f = $this->fixtures();

        Livewire::actingAs($this->admin($f['tenant']))
            ->test(HotspotPackageIndex::class)
            ->set('networkProfileGroupId', (string) $f['group']->id)
            ->set('bandwidthProfileId', (string) $f['bandwidth']->id)
            ->set('name', 'Paket Kuota')
            ->set('profileType', 'limited')
            ->set('limitType', 'quota_base')
            ->set('activeDurationValue', '30')
            ->set('quotaValue', '2.5')
            ->set('quotaUnit', 'gb')
            ->call('createPackage')
            ->assertHasNoErrors();

        $package = HotspotPackage::where('name', 'Paket Kuota')->first();
        $this->assertSame('quota_base', $package->limit_type->value);
        $this->assertSame('2.50', (string) $package->quota_value);
        $this->assertSame('gb', $package->quota_unit->value);
    }

    /**
     * The whole reason quotaUnit's own property default is empty string,
     * not 'mb' — switching Batasan back to TimeBase after having selected
     * QuotaBase must genuinely clear both quota fields, not leave a stale
     * value that would silently fail prohibited_unless on submit (or,
     * worse, silently persist alongside a TimeBase package).
     */
    public function test_switching_from_quota_base_back_to_time_base_clears_quota_fields(): void
    {
        $f = $this->fixtures();

        Livewire::actingAs($this->admin($f['tenant']))
            ->test(HotspotPackageIndex::class)
            ->set('showCreateForm', true)
            ->set('profileType', 'limited')
            ->set('limitType', 'quota_base')
            ->set('quotaValue', '5')
            ->assertSet('quotaUnit', 'mb')
            ->set('limitType', 'time_base')
            ->assertSet('quotaValue', '')
            ->assertSet('quotaUnit', '');
    }

    /**
     * Same as above, but switching Tipe Profil straight to Unlimited
     * (which hides Batasan/Masa Aktif/Kuota all at once).
     */
    public function test_switching_profile_type_to_unlimited_clears_quota_fields(): void
    {
        $f = $this->fixtures();

        Livewire::actingAs($this->admin($f['tenant']))
            ->test(HotspotPackageIndex::class)
            ->set('showCreateForm', true)
            ->set('profileType', 'limited')
            ->set('limitType', 'quota_base')
            ->set('quotaValue', '5')
            ->set('profileType', 'unlimited')
            ->assertSet('quotaValue', '')
            ->assertSet('quotaUnit', '');
    }

    public function test_editing_a_quota_base_package_prefills_quota_fields(): void
    {
        $f = $this->fixtures();
        $package = HotspotPackage::factory()->quotaBase()->create([
            'network_profile_group_id' => $f['group']->id, 'bandwidth_profile_id' => $f['bandwidth']->id,
            'quota_value' => 1.5, 'quota_unit' => 'gb',
        ]);

        Livewire::actingAs($this->admin($f['tenant']))
            ->test(HotspotPackageIndex::class)
            ->call('edit', $package->id)
            ->assertSet('editQuotaValue', '1.50')
            ->assertSet('editQuotaUnit', 'gb')
            ->call('updatePackage')
            ->assertHasNoErrors();
    }

    /**
     * Server-side enforcement, not just the dropdown filter — forcing a
     * PPP-type group id directly (bypassing the dropdown's own query
     * scope) proves the backend cross-check itself works.
     */
    public function test_a_ppp_type_group_forced_directly_is_rejected(): void
    {
        $f = $this->fixtures();
        $pppGroup = NetworkProfileGroup::factory()->create([
            'nas_id' => $f['nas']->id,
            'customer_ip_pool_id' => CustomerIpPool::factory()->create(['nas_id' => $f['nas']->id])->id,
            'type' => NetworkProfileGroupType::Ppp,
        ]);

        $component = Livewire::actingAs($this->admin($f['tenant']))
            ->test(HotspotPackageIndex::class)
            ->set('networkProfileGroupId', (string) $pppGroup->id)
            ->set('bandwidthProfileId', (string) $f['bandwidth']->id)
            ->set('name', 'Paket Salah')
            ->call('createPackage');

        $component->assertHasErrors('networkProfileGroupId');
        $this->assertDatabaseMissing('hotspot_packages', ['name' => 'Paket Salah']);
    }

    public function test_sell_price_below_cost_price_is_rejected_via_the_form(): void
    {
        $f = $this->fixtures();

        $component = Livewire::actingAs($this->admin($f['tenant']))
            ->test(HotspotPackageIndex::class)
            ->set('networkProfileGroupId', (string) $f['group']->id)
            ->set('bandwidthProfileId', (string) $f['bandwidth']->id)
            ->set('name', 'Paket Rugi')
            ->set('costPrice', '10000')
            ->set('sellPrice', '5000')
            ->call('createPackage');

        $component->assertHasErrors('sellPrice');
    }

    public function test_editing_a_package(): void
    {
        $f = $this->fixtures();
        $package = HotspotPackage::factory()->create(['network_profile_group_id' => $f['group']->id, 'bandwidth_profile_id' => $f['bandwidth']->id, 'name' => 'Lama']);

        Livewire::actingAs($this->admin($f['tenant']))
            ->test(HotspotPackageIndex::class)
            ->call('edit', $package->id)
            ->assertSet('editName', 'Lama')
            ->set('editName', 'Baru')
            ->call('updatePackage')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('hotspot_packages', ['id' => $package->id, 'name' => 'Baru']);
    }

    public function test_deleting_a_package_soft_deletes_it(): void
    {
        $f = $this->fixtures();
        $package = HotspotPackage::factory()->create(['network_profile_group_id' => $f['group']->id, 'bandwidth_profile_id' => $f['bandwidth']->id]);

        Livewire::actingAs($this->admin($f['tenant']))
            ->test(HotspotPackageIndex::class)
            ->call('deletePackage', $package->id);

        $this->assertSoftDeleted('hotspot_packages', ['id' => $package->id]);
    }

    public function test_non_admin_tier_user_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('customer_service');

        Livewire::actingAs($user)->test(HotspotPackageIndex::class)->assertForbidden();
    }

    public function test_sync_ulang_button_only_shows_for_a_failed_package(): void
    {
        $f = $this->fixtures();
        $package = HotspotPackage::factory()->create(['network_profile_group_id' => $f['group']->id, 'bandwidth_profile_id' => $f['bandwidth']->id]);
        $package->markSyncFailed('router unreachable');

        $html = Livewire::actingAs($this->admin($f['tenant']))->test(HotspotPackageIndex::class)->html();

        $this->assertStringContainsString('Sync Ulang', $html);
    }

    public function test_wire_poll_is_present_when_a_visible_row_is_pending(): void
    {
        $f = $this->fixtures();
        HotspotPackage::factory()->create(['network_profile_group_id' => $f['group']->id, 'bandwidth_profile_id' => $f['bandwidth']->id]);

        $html = Livewire::actingAs($this->admin($f['tenant']))->test(HotspotPackageIndex::class)->html();

        $this->assertStringContainsString('wire:poll.5s', $html);
    }

    public function test_wire_poll_is_absent_when_no_visible_row_is_pending(): void
    {
        $f = $this->fixtures();
        $package = HotspotPackage::factory()->create(['network_profile_group_id' => $f['group']->id, 'bandwidth_profile_id' => $f['bandwidth']->id]);
        $package->markSynced();

        $html = Livewire::actingAs($this->admin($f['tenant']))->test(HotspotPackageIndex::class)->html();

        $this->assertStringNotContainsString('wire:poll.5s', $html);
    }
}
