<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\BandwidthProfileIndex;
use App\Models\BandwidthProfile;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BandwidthProfileIndexLivewireTest extends TestCase
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

    public function test_creating_with_kbps_unit_stores_the_value_as_is(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(BandwidthProfileIndex::class)
            ->set('name', 'Kbps Test')
            ->set('unit', 'Kbps')
            ->set('uploadMin', '512')
            ->set('uploadMax', '1024')
            ->set('downloadMin', '1024')
            ->set('downloadMax', '2048')
            ->call('createProfile')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('bandwidth_profiles', [
            'name' => 'Kbps Test',
            'upload_min' => 512,
            'upload_max' => 1024,
            'download_min' => 1024,
            'download_max' => 2048,
        ]);
    }

    public function test_creating_with_mbps_unit_converts_to_kbps_before_saving(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(BandwidthProfileIndex::class)
            ->set('name', 'Mbps Test')
            ->set('unit', 'Mbps')
            ->set('uploadMin', '5')
            ->set('uploadMax', '10')
            ->set('downloadMin', '10')
            ->set('downloadMax', '20')
            ->call('createProfile')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('bandwidth_profiles', [
            'name' => 'Mbps Test',
            'upload_min' => 5000,
            'upload_max' => 10000,
            'download_min' => 10000,
            'download_max' => 20000,
        ]);
    }

    public function test_upload_max_less_than_upload_min_after_conversion_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(BandwidthProfileIndex::class)
            ->set('name', 'Invalid')
            ->set('unit', 'Mbps')
            ->set('uploadMin', '10')
            ->set('uploadMax', '5')
            ->set('downloadMin', '5')
            ->set('downloadMax', '10')
            ->call('createProfile');

        $component->assertHasErrors('uploadMax');
        $this->assertDatabaseMissing('bandwidth_profiles', ['name' => 'Invalid']);
    }

    /**
     * Real bug found via manual UI testing (v0.14.1): "10Mbps" and
     * "10Mbps " (a trailing space) passed as two distinct active rows —
     * Livewire's inline validate() doesn't go through
     * FormRequest::prepareForValidation(), so createProfile() needed its
     * own explicit trim(). Reproduces the exact scenario via this
     * component, not just the REST API.
     */
    public function test_name_with_trailing_whitespace_is_trimmed_and_rejected_as_a_duplicate(): void
    {
        $tenant = Tenant::factory()->create();
        BandwidthProfile::factory()->create(['tenant_id' => $tenant->id, 'name' => '10Mbps']);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(BandwidthProfileIndex::class)
            ->set('name', '10Mbps ')
            ->set('unit', 'Kbps')
            ->set('uploadMin', '5000')
            ->set('uploadMax', '10000')
            ->set('downloadMin', '5000')
            ->set('downloadMax', '10000')
            ->call('createProfile');

        $component->assertHasErrors('name');
        $this->assertSame(1, BandwidthProfile::withTrashed()->where('tenant_id', $tenant->id)->where('name', '10Mbps')->count());
    }

    /**
     * Update path (editName) gets the same trim-before-validate fix —
     * confirmed independently since it's a separate method/property from
     * createProfile()/name above.
     */
    public function test_editing_to_a_name_with_trailing_whitespace_is_trimmed_and_rejected_as_a_duplicate(): void
    {
        $tenant = Tenant::factory()->create();
        BandwidthProfile::factory()->create(['tenant_id' => $tenant->id, 'name' => '10Mbps']);
        $editing = BandwidthProfile::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Lama']);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(BandwidthProfileIndex::class)
            ->call('edit', $editing->id)
            ->set('editName', '10Mbps ')
            ->call('updateProfile');

        $component->assertHasErrors('editName');
        $this->assertDatabaseHas('bandwidth_profiles', ['id' => $editing->id, 'name' => 'Lama']);
    }

    /**
     * Explicit confirmation requested after the trailing-space fix: trim()
     * only strips leading/trailing whitespace, never internal — "15 Mbps"
     * (space between number and unit) must survive createProfile()'s own
     * trim() call intact, not collapse into "15Mbps".
     */
    public function test_create_preserves_internal_whitespace_within_the_name(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(BandwidthProfileIndex::class)
            ->set('name', '  15 Mbps  ')
            ->set('unit', 'Kbps')
            ->set('uploadMin', '5000')
            ->set('uploadMax', '10000')
            ->set('downloadMin', '5000')
            ->set('downloadMax', '10000')
            ->call('createProfile')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('bandwidth_profiles', ['name' => '15 Mbps', 'tenant_id' => $tenant->id]);
        $this->assertDatabaseMissing('bandwidth_profiles', ['name' => '15Mbps', 'tenant_id' => $tenant->id]);
    }

    /** Same guarantee on the edit path (editName), via a separate property/method. */
    public function test_edit_preserves_internal_whitespace_within_the_name(): void
    {
        $tenant = Tenant::factory()->create();
        $editing = BandwidthProfile::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Lama']);

        Livewire::actingAs($this->admin($tenant))
            ->test(BandwidthProfileIndex::class)
            ->call('edit', $editing->id)
            ->set('editName', '  15 Mbps  ')
            ->call('updateProfile')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('bandwidth_profiles', ['id' => $editing->id, 'name' => '15 Mbps']);
    }

    public function test_non_admin_tier_user_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('customer_service');

        Livewire::actingAs($user)->test(BandwidthProfileIndex::class)->assertForbidden();
    }

    public function test_deleting_a_profile_soft_deletes_it(): void
    {
        $tenant = Tenant::factory()->create();
        $profile = BandwidthProfile::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(BandwidthProfileIndex::class)
            ->call('deleteProfile', $profile->id);

        $this->assertSoftDeleted('bandwidth_profiles', ['id' => $profile->id]);
    }

    public function test_sorting_toggles_direction_on_same_column(): void
    {
        $tenant = Tenant::factory()->create();
        BandwidthProfile::factory()->create(['tenant_id' => $tenant->id, 'name' => 'A Profile']);
        BandwidthProfile::factory()->create(['tenant_id' => $tenant->id, 'name' => 'B Profile']);

        Livewire::actingAs($this->admin($tenant))
            ->test(BandwidthProfileIndex::class)
            ->assertSet('sortDir', 'asc')
            ->call('sortByColumn', 'name')
            ->assertSet('sortDir', 'desc');
    }
}
