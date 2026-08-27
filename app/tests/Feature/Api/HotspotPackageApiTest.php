<?php

namespace Tests\Feature\Api;

use App\Enums\NetworkProfileGroupType;
use App\Jobs\PushHotspotPackageToMikrotikJob;
use App\Models\BandwidthProfile;
use App\Models\CustomerIpPool;
use App\Models\HotspotPackage;
use App\Models\Nas;
use App\Models\NetworkProfileGroup;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class HotspotPackageApiTest extends TestCase
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

    private function nonAdminStaff(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('customer_service');

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

    private function payload(int $groupId, int $bandwidthId, array $overrides = []): array
    {
        return array_merge([
            'network_profile_group_id' => $groupId,
            'bandwidth_profile_id' => $bandwidthId,
            'name' => 'Paket Hotspot 10rb',
            'cost_price' => 2000,
            'sell_price' => 5000,
            'tax_percent' => 0,
            'profile_type' => 'unlimited',
            'shared_users' => 1,
        ], $overrides);
    }

    public function test_admin_can_create_a_hotspot_package(): void
    {
        Bus::fake();
        $f = $this->fixtures();

        $response = $this->actingAs($this->admin($f['tenant']))->postJson('/api/v1/hotspot-packages', $this->payload($f['group']->id, $f['bandwidth']->id));

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Paket Hotspot 10rb');
        $response->assertJsonPath('data.profile_type', 'unlimited');
        $this->assertDatabaseHas('hotspot_packages', ['name' => 'Paket Hotspot 10rb', 'network_profile_group_id' => $f['group']->id]);
    }

    public function test_a_role_without_hotspot_packages_permission_cannot_list(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->nonAdminStaff($tenant))->getJson('/api/v1/hotspot-packages');

        $response->assertForbidden();
    }

    /**
     * The whole reason this validation exists — see StoreHotspotPackageRequest's
     * own docblock.
     */
    public function test_a_ppp_type_network_profile_group_is_rejected(): void
    {
        Bus::fake();
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $pppGroup = NetworkProfileGroup::factory()->create([
            'nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id, 'type' => NetworkProfileGroupType::Ppp,
        ]);
        $bandwidth = BandwidthProfile::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/hotspot-packages', $this->payload($pppGroup->id, $bandwidth->id));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['network_profile_group_id']);
        $this->assertDatabaseMissing('hotspot_packages', ['name' => 'Paket Hotspot 10rb']);
    }

    public function test_a_soft_deleted_bandwidth_profile_is_rejected(): void
    {
        Bus::fake();
        $f = $this->fixtures();
        $f['bandwidth']->delete();

        $response = $this->actingAs($this->admin($f['tenant']))->postJson('/api/v1/hotspot-packages', $this->payload($f['group']->id, $f['bandwidth']->id));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['bandwidth_profile_id']);
    }

    public function test_a_soft_deleted_network_profile_group_is_rejected(): void
    {
        Bus::fake();
        $f = $this->fixtures();
        $f['group']->delete();

        $response = $this->actingAs($this->admin($f['tenant']))->postJson('/api/v1/hotspot-packages', $this->payload($f['group']->id, $f['bandwidth']->id));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['network_profile_group_id']);
    }

    public function test_sell_price_below_cost_price_is_rejected(): void
    {
        Bus::fake();
        $f = $this->fixtures();

        $response = $this->actingAs($this->admin($f['tenant']))->postJson('/api/v1/hotspot-packages', $this->payload($f['group']->id, $f['bandwidth']->id, ['cost_price' => 5000, 'sell_price' => 2000]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['sell_price']);
    }

    public function test_sell_price_equal_to_cost_price_is_allowed(): void
    {
        Bus::fake();
        $f = $this->fixtures();

        $response = $this->actingAs($this->admin($f['tenant']))->postJson('/api/v1/hotspot-packages', $this->payload($f['group']->id, $f['bandwidth']->id, ['cost_price' => 3000, 'sell_price' => 3000]));

        $response->assertCreated();
    }

    public function test_limited_profile_requires_limit_type_and_duration(): void
    {
        Bus::fake();
        $f = $this->fixtures();

        $response = $this->actingAs($this->admin($f['tenant']))->postJson('/api/v1/hotspot-packages', $this->payload($f['group']->id, $f['bandwidth']->id, ['profile_type' => 'limited']));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['limit_type', 'active_duration_value', 'active_duration_unit']);
    }

    public function test_limited_profile_with_full_duration_data_is_accepted(): void
    {
        Bus::fake();
        $f = $this->fixtures();

        $response = $this->actingAs($this->admin($f['tenant']))->postJson('/api/v1/hotspot-packages', $this->payload($f['group']->id, $f['bandwidth']->id, [
            'profile_type' => 'limited', 'limit_type' => 'time_base',
            'active_duration_value' => 1, 'active_duration_unit' => 'day',
        ]));

        $response->assertCreated();
        $response->assertJsonPath('data.limit_type', 'time_base');
        $response->assertJsonPath('data.active_duration_value', 1);
    }

    /**
     * v0.14.4 amendment — real gap confirmed by Agung via screenshot:
     * limit_type=quota_base packages need quota_value/quota_unit, missing
     * from the original migration. Backend enforcement, mirroring the
     * exact same required_if/prohibited_unless pair the Livewire form's
     * own validate() call uses.
     */
    public function test_quota_base_requires_quota_value_and_unit(): void
    {
        Bus::fake();
        $f = $this->fixtures();

        $response = $this->actingAs($this->admin($f['tenant']))->postJson('/api/v1/hotspot-packages', $this->payload($f['group']->id, $f['bandwidth']->id, [
            'profile_type' => 'limited', 'limit_type' => 'quota_base',
            'active_duration_value' => 30, 'active_duration_unit' => 'day',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['quota_value', 'quota_unit']);
    }

    public function test_quota_base_with_full_quota_data_is_accepted(): void
    {
        Bus::fake();
        $f = $this->fixtures();

        $response = $this->actingAs($this->admin($f['tenant']))->postJson('/api/v1/hotspot-packages', $this->payload($f['group']->id, $f['bandwidth']->id, [
            'profile_type' => 'limited', 'limit_type' => 'quota_base',
            'active_duration_value' => 30, 'active_duration_unit' => 'day',
            'quota_value' => 2.5, 'quota_unit' => 'gb',
        ]));

        $response->assertCreated();
        $response->assertJsonPath('data.quota_value', 2.5);
        $response->assertJsonPath('data.quota_unit', 'gb');
    }

    /**
     * quota_value/quota_unit are PROHIBITED (not just optional) once
     * limit_type isn't quota_base — a direct API call carrying a leftover
     * value must be rejected, not silently accepted and ignored.
     */
    public function test_quota_fields_are_prohibited_for_a_time_base_package(): void
    {
        Bus::fake();
        $f = $this->fixtures();

        $response = $this->actingAs($this->admin($f['tenant']))->postJson('/api/v1/hotspot-packages', $this->payload($f['group']->id, $f['bandwidth']->id, [
            'profile_type' => 'limited', 'limit_type' => 'time_base',
            'active_duration_value' => 1, 'active_duration_unit' => 'day',
            'quota_value' => 5, 'quota_unit' => 'gb',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['quota_value', 'quota_unit']);
    }

    public function test_quota_fields_are_prohibited_for_an_unlimited_package(): void
    {
        Bus::fake();
        $f = $this->fixtures();

        $response = $this->actingAs($this->admin($f['tenant']))->postJson('/api/v1/hotspot-packages', $this->payload($f['group']->id, $f['bandwidth']->id, [
            'quota_value' => 5, 'quota_unit' => 'gb',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['quota_value', 'quota_unit']);
    }

    public function test_login_end_time_before_start_time_is_rejected(): void
    {
        Bus::fake();
        $f = $this->fixtures();

        $response = $this->actingAs($this->admin($f['tenant']))->postJson('/api/v1/hotspot-packages', $this->payload($f['group']->id, $f['bandwidth']->id, [
            'login_start_time' => '20:00', 'login_end_time' => '08:00',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['login_end_time']);
    }

    public function test_login_days_accepts_a_valid_subset_and_rejects_an_invalid_day(): void
    {
        Bus::fake();
        $f = $this->fixtures();

        $valid = $this->actingAs($this->admin($f['tenant']))->postJson('/api/v1/hotspot-packages', $this->payload($f['group']->id, $f['bandwidth']->id, [
            'login_days' => ['monday', 'wednesday', 'friday'],
        ]));
        $valid->assertCreated();
        $valid->assertJsonPath('data.login_days', ['monday', 'wednesday', 'friday']);

        $invalid = $this->actingAs($this->admin($f['tenant']))->postJson('/api/v1/hotspot-packages', $this->payload($f['group']->id, $f['bandwidth']->id, [
            'name' => 'Paket Lain', 'login_days' => ['funday'],
        ]));
        $invalid->assertUnprocessable();
        $invalid->assertJsonValidationErrors(['login_days.0']);
    }

    public function test_same_package_name_on_the_same_group_is_rejected(): void
    {
        Bus::fake();
        $f = $this->fixtures();
        HotspotPackage::factory()->create(['network_profile_group_id' => $f['group']->id, 'name' => 'Paket Hotspot 10rb']);

        $response = $this->actingAs($this->admin($f['tenant']))->postJson('/api/v1/hotspot-packages', $this->payload($f['group']->id, $f['bandwidth']->id));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_same_package_name_on_a_different_group_is_allowed(): void
    {
        Bus::fake();
        $f = $this->fixtures();
        $f2 = $this->fixtures();
        HotspotPackage::factory()->create(['network_profile_group_id' => $f['group']->id, 'name' => 'Paket Hotspot 10rb']);

        $response = $this->actingAs($this->admin($f2['tenant']))->postJson('/api/v1/hotspot-packages', $this->payload($f2['group']->id, $f2['bandwidth']->id));

        $response->assertCreated();
    }

    public function test_admin_can_update_a_hotspot_package(): void
    {
        Bus::fake();
        $f = $this->fixtures();
        $package = HotspotPackage::factory()->create(['network_profile_group_id' => $f['group']->id, 'bandwidth_profile_id' => $f['bandwidth']->id, 'name' => 'Lama']);

        $response = $this->actingAs($this->admin($f['tenant']))->putJson("/api/v1/hotspot-packages/{$package->id}", ['name' => 'Baru']);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Baru');
    }

    public function test_updating_to_a_ppp_type_group_is_rejected(): void
    {
        Bus::fake();
        $f = $this->fixtures();
        $package = HotspotPackage::factory()->create(['network_profile_group_id' => $f['group']->id, 'bandwidth_profile_id' => $f['bandwidth']->id]);
        $pppGroup = NetworkProfileGroup::factory()->create([
            'nas_id' => $f['nas']->id, 'customer_ip_pool_id' => CustomerIpPool::factory()->create(['nas_id' => $f['nas']->id])->id, 'type' => NetworkProfileGroupType::Ppp,
        ]);

        $response = $this->actingAs($this->admin($f['tenant']))->putJson("/api/v1/hotspot-packages/{$package->id}", ['network_profile_group_id' => $pppGroup->id]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['network_profile_group_id']);
    }

    public function test_updating_an_unlimited_package_to_quota_base_requires_quota_fields(): void
    {
        Bus::fake();
        $f = $this->fixtures();
        $package = HotspotPackage::factory()->create(['network_profile_group_id' => $f['group']->id, 'bandwidth_profile_id' => $f['bandwidth']->id]);

        $response = $this->actingAs($this->admin($f['tenant']))->putJson("/api/v1/hotspot-packages/{$package->id}", [
            'profile_type' => 'limited', 'limit_type' => 'quota_base',
            'active_duration_value' => 30, 'active_duration_unit' => 'day',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['quota_value', 'quota_unit']);
    }

    public function test_updating_a_quota_base_package_with_full_quota_data_succeeds(): void
    {
        Bus::fake();
        $f = $this->fixtures();
        $package = HotspotPackage::factory()->create(['network_profile_group_id' => $f['group']->id, 'bandwidth_profile_id' => $f['bandwidth']->id]);

        $response = $this->actingAs($this->admin($f['tenant']))->putJson("/api/v1/hotspot-packages/{$package->id}", [
            'profile_type' => 'limited', 'limit_type' => 'quota_base',
            'active_duration_value' => 30, 'active_duration_unit' => 'day',
            'quota_value' => 1, 'quota_unit' => 'gb',
        ]);

        $response->assertOk();
        // 1.0 as a whole-number float JSON-encodes as plain "1", not
        // "1.0" — assertJsonPath's strict comparison needs the int form
        // here, unlike test_quota_base_with_full_quota_data_is_accepted's
        // own 2.5 (never whole, so it round-trips as a real float).
        $response->assertJsonPath('data.quota_value', 1);
        $response->assertJsonPath('data.quota_unit', 'gb');
    }

    public function test_admin_can_soft_delete_a_hotspot_package(): void
    {
        Bus::fake();
        $f = $this->fixtures();
        $package = HotspotPackage::factory()->create(['network_profile_group_id' => $f['group']->id, 'bandwidth_profile_id' => $f['bandwidth']->id]);

        $response = $this->actingAs($this->admin($f['tenant']))->deleteJson("/api/v1/hotspot-packages/{$package->id}");

        $response->assertOk();
        $this->assertSoftDeleted('hotspot_packages', ['id' => $package->id]);
    }

    public function test_index_can_be_filtered_by_network_profile_group(): void
    {
        $f = $this->fixtures();
        $otherGroup = NetworkProfileGroup::factory()->create([
            'nas_id' => $f['nas']->id, 'customer_ip_pool_id' => CustomerIpPool::factory()->create(['nas_id' => $f['nas']->id])->id, 'type' => NetworkProfileGroupType::Hotspot,
        ]);
        HotspotPackage::factory()->create(['network_profile_group_id' => $f['group']->id, 'bandwidth_profile_id' => $f['bandwidth']->id, 'name' => 'Grup A Paket']);
        HotspotPackage::factory()->create(['network_profile_group_id' => $otherGroup->id, 'bandwidth_profile_id' => $f['bandwidth']->id, 'name' => 'Grup B Paket']);

        $response = $this->actingAs($this->admin($f['tenant']))->getJson('/api/v1/hotspot-packages?network_profile_group_id='.$f['group']->id);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Grup A Paket');
    }

    public function test_hotspot_packages_from_another_tenant_are_not_visible(): void
    {
        $f = $this->fixtures();
        HotspotPackage::factory()->create(['network_profile_group_id' => $f['group']->id, 'bandwidth_profile_id' => $f['bandwidth']->id, 'name' => 'Tenant A Paket']);
        $otherTenantPackage = HotspotPackage::factory()->create(['name' => 'Tenant B Paket']);

        $response = $this->actingAs($this->admin($f['tenant']))->getJson('/api/v1/hotspot-packages');

        $response->assertOk();
        $response->assertJsonMissing(['name' => 'Tenant B Paket']);
        $this->assertNotSame($f['tenant']->id, $otherTenantPackage->tenant_id);
    }

    public function test_create_dispatches_the_push_job(): void
    {
        Bus::fake();
        $f = $this->fixtures();

        $response = $this->actingAs($this->admin($f['tenant']))->postJson('/api/v1/hotspot-packages', $this->payload($f['group']->id, $f['bandwidth']->id));

        $response->assertCreated();
        $packageId = $response->json('data.id');
        Bus::assertDispatched(PushHotspotPackageToMikrotikJob::class, fn ($job) => $job->hotspotPackageId === $packageId);
    }

    public function test_admin_can_trigger_a_manual_resync_for_a_failed_package(): void
    {
        $f = $this->fixtures();
        $package = HotspotPackage::factory()->create(['network_profile_group_id' => $f['group']->id, 'bandwidth_profile_id' => $f['bandwidth']->id]);
        $package->markSyncFailed('router unreachable');

        Bus::fake();

        $response = $this->actingAs($this->admin($f['tenant']))->postJson("/api/v1/hotspot-packages/{$package->id}/resync");

        $response->assertOk();
        $response->assertJsonPath('data.mikrotik_sync_status', 'pending');
        Bus::assertDispatched(PushHotspotPackageToMikrotikJob::class, fn ($job) => $job->hotspotPackageId === $package->id);
    }
}
