<?php

namespace Tests\Feature\Api;

use App\Enums\NetworkProfileGroupType;
use App\Jobs\PushPppPackageToMikrotikJob;
use App\Models\BandwidthProfile;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class PppPackageApiTest extends TestCase
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
            'nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id, 'type' => NetworkProfileGroupType::Ppp,
        ]);
        $bandwidth = BandwidthProfile::factory()->create(['tenant_id' => $tenant->id]);

        return compact('tenant', 'nas', 'group', 'bandwidth');
    }

    private function payload(int $groupId, int $bandwidthId, array $overrides = []): array
    {
        return array_merge([
            'network_profile_group_id' => $groupId,
            'bandwidth_profile_id' => $bandwidthId,
            'name' => 'Paket PPP Bulanan',
            'cost_price' => 50000,
            'sell_price' => 100000,
            'tax_percent' => 0,
            'active_duration_value' => 1,
            'active_duration_unit' => 'month',
            'shared_users' => 1,
        ], $overrides);
    }

    public function test_creating_without_a_group_is_rejected(): void
    {
        Bus::fake();
        $f = $this->fixtures();
        $payload = $this->payload($f['group']->id, $f['bandwidth']->id);
        unset($payload['network_profile_group_id']);

        $response = $this->actingAs($this->admin($f['tenant']))->postJson('/api/v1/ppp-packages', $payload);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['network_profile_group_id']);
        $this->assertDatabaseMissing('ppp_packages', ['name' => 'Paket PPP Bulanan']);
    }

    public function test_admin_can_create_a_ppp_package(): void
    {
        Bus::fake();
        $f = $this->fixtures();

        $response = $this->actingAs($this->admin($f['tenant']))->postJson('/api/v1/ppp-packages', $this->payload($f['group']->id, $f['bandwidth']->id));

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Paket PPP Bulanan');
        $response->assertJsonPath('data.active_duration_unit', 'month');
        $response->assertJsonPath('data.is_unlimited_duration', false);
        $this->assertDatabaseHas('ppp_packages', ['network_profile_group_id' => $f['group']->id, 'name' => 'Paket PPP Bulanan']);
        Bus::assertDispatched(PushPppPackageToMikrotikJob::class);
    }

    public function test_admin_can_create_an_unlimited_duration_package_with_masa_aktif_zero(): void
    {
        Bus::fake();
        $f = $this->fixtures();

        $response = $this->actingAs($this->admin($f['tenant']))->postJson(
            '/api/v1/ppp-packages',
            $this->payload($f['group']->id, $f['bandwidth']->id, [
                'name' => 'Paket Gratis Unlimited',
                'active_duration_value' => 0,
            ])
        );

        $response->assertCreated();
        $response->assertJsonPath('data.active_duration_value', 0);
        $response->assertJsonPath('data.is_unlimited_duration', true);
        $this->assertDatabaseHas('ppp_packages', ['name' => 'Paket Gratis Unlimited', 'active_duration_value' => 0]);
    }

    public function test_negative_masa_aktif_is_still_rejected(): void
    {
        Bus::fake();
        $f = $this->fixtures();

        $this->actingAs($this->admin($f['tenant']))->postJson(
            '/api/v1/ppp-packages',
            $this->payload($f['group']->id, $f['bandwidth']->id, ['active_duration_value' => -1])
        )->assertUnprocessable()->assertJsonValidationErrors('active_duration_value');
    }

    public function test_a_role_without_ppp_packages_permission_cannot_list(): void
    {
        $f = $this->fixtures();

        $response = $this->actingAs($this->nonAdminStaff($f['tenant']))->getJson('/api/v1/ppp-packages');

        $response->assertForbidden();
    }

    public function test_a_hotspot_type_group_is_rejected(): void
    {
        Bus::fake();
        $f = $this->fixtures();
        $hotspotPool = CustomerIpPool::factory()->create(['nas_id' => $f['nas']->id]);
        $hotspotGroup = NetworkProfileGroup::factory()->create([
            'nas_id' => $f['nas']->id, 'customer_ip_pool_id' => $hotspotPool->id, 'type' => NetworkProfileGroupType::Hotspot,
        ]);

        $response = $this->actingAs($this->admin($f['tenant']))->postJson('/api/v1/ppp-packages', $this->payload($hotspotGroup->id, $f['bandwidth']->id));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['network_profile_group_id']);
    }

    public function test_a_name_colliding_with_the_parent_grup_profils_own_name_is_rejected(): void
    {
        Bus::fake();
        $f = $this->fixtures();

        $response = $this->actingAs($this->admin($f['tenant']))->postJson('/api/v1/ppp-packages', $this->payload($f['group']->id, $f['bandwidth']->id, ['name' => $f['group']->name]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_a_name_colliding_with_another_ppp_package_on_a_sibling_group_same_nas_is_rejected(): void
    {
        Bus::fake();
        $f = $this->fixtures();
        $siblingPool = CustomerIpPool::factory()->create(['nas_id' => $f['nas']->id]);
        $siblingGroup = NetworkProfileGroup::factory()->create([
            'nas_id' => $f['nas']->id, 'customer_ip_pool_id' => $siblingPool->id, 'type' => NetworkProfileGroupType::Ppp,
        ]);
        PppPackage::factory()->create(['network_profile_group_id' => $siblingGroup->id, 'name' => 'Paket-Existing']);

        $response = $this->actingAs($this->admin($f['tenant']))->postJson('/api/v1/ppp-packages', $this->payload($f['group']->id, $f['bandwidth']->id, ['name' => 'Paket-Existing']));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_a_name_matching_a_grup_profil_on_a_different_nas_is_allowed(): void
    {
        Bus::fake();
        $f = $this->fixtures();
        $otherNas = Nas::factory()->create(['tenant_id' => $f['tenant']->id]);
        $otherPool = CustomerIpPool::factory()->create(['nas_id' => $otherNas->id]);
        NetworkProfileGroup::factory()->create([
            'nas_id' => $otherNas->id, 'customer_ip_pool_id' => $otherPool->id, 'type' => NetworkProfileGroupType::Ppp, 'name' => 'Nama-Kembar',
        ]);

        $response = $this->actingAs($this->admin($f['tenant']))->postJson('/api/v1/ppp-packages', $this->payload($f['group']->id, $f['bandwidth']->id, ['name' => 'Nama-Kembar']));

        $response->assertCreated();
    }

    public function test_admin_can_update_a_ppp_package(): void
    {
        Bus::fake();
        $f = $this->fixtures();
        $package = PppPackage::factory()->create(['network_profile_group_id' => $f['group']->id, 'name' => 'Lama']);

        $response = $this->actingAs($this->admin($f['tenant']))->putJson("/api/v1/ppp-packages/{$package->id}", ['name' => 'Baru']);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Baru');
    }

    public function test_admin_can_soft_delete_a_ppp_package(): void
    {
        Bus::fake();
        $f = $this->fixtures();
        $package = PppPackage::factory()->create(['network_profile_group_id' => $f['group']->id]);

        $response = $this->actingAs($this->admin($f['tenant']))->deleteJson("/api/v1/ppp-packages/{$package->id}");

        $response->assertOk();
        $this->assertSoftDeleted('ppp_packages', ['id' => $package->id]);
    }

    public function test_admin_can_trigger_a_manual_resync_for_a_failed_package(): void
    {
        Bus::fake();
        $f = $this->fixtures();
        $package = PppPackage::factory()->create([
            'network_profile_group_id' => $f['group']->id,
            'mikrotik_sync_status' => 'failed',
            'mikrotik_sync_error' => 'connection timed out',
        ]);

        $response = $this->actingAs($this->admin($f['tenant']))->postJson("/api/v1/ppp-packages/{$package->id}/resync");

        $response->assertOk();
        $response->assertJsonPath('data.mikrotik_sync_status', 'pending');
        Bus::assertDispatched(PushPppPackageToMikrotikJob::class, fn ($job) => $job->pppPackageId === $package->id);
    }

    public function test_ppp_packages_from_another_tenant_are_not_visible(): void
    {
        $f = $this->fixtures();
        PppPackage::factory()->create(['network_profile_group_id' => $f['group']->id, 'name' => 'Punya Tenant Lain']);

        $otherTenant = Tenant::factory()->create();
        $response = $this->actingAs($this->admin($otherTenant))->getJson('/api/v1/ppp-packages');

        $response->assertOk();
        $response->assertJsonMissing(['name' => 'Punya Tenant Lain']);
    }
}
