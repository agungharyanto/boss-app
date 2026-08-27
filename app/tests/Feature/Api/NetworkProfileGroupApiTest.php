<?php

namespace Tests\Feature\Api;

use App\Jobs\PushNetworkProfileGroupToMikrotikJob;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Models\NetworkProfileGroup;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NetworkProfileGroupApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        // Same isolation approach as RadiusSessionHistoryServiceTest —
        // the `radius` connection normally points at the real, separate
        // radius_db. Only the columns NetworkProfileGroupService actually
        // writes are created here.
        config(['database.connections.radius' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        DB::purge('radius');

        DB::connection('radius')->statement('
            CREATE TABLE radgroupreply (
                id INTEGER PRIMARY KEY,
                groupname TEXT,
                attribute TEXT,
                op TEXT,
                value TEXT
            )
        ');
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

    private function payload(int $nasId, int $poolId, array $overrides = []): array
    {
        return array_merge([
            'nas_id' => $nasId,
            'name' => 'Grup Utama',
            'type' => 'ppp',
            'customer_ip_pool_id' => $poolId,
            'dns_primary' => '8.8.8.8',
            'dns_secondary' => '8.8.4.4',
        ], $overrides);
    }

    public function test_admin_can_create_a_network_profile_group(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/network-profile-groups', $this->payload($nas->id, $pool->id));

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Grup Utama');
        $response->assertJsonPath('data.type', 'ppp');
        $this->assertDatabaseHas('network_profile_groups', ['name' => 'Grup Utama', 'nas_id' => $nas->id, 'tenant_id' => $tenant->id]);
    }

    public function test_a_role_without_network_profile_groups_permission_cannot_list(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->nonAdminStaff($tenant))->getJson('/api/v1/network-profile-groups');

        $response->assertForbidden();
    }

    public function test_customer_ip_pool_from_a_different_nas_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $poolFromNasB = CustomerIpPool::factory()->create(['nas_id' => $nasB->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/network-profile-groups', $this->payload($nasA->id, $poolFromNasB->id));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['customer_ip_pool_id']);
        $this->assertDatabaseMissing('network_profile_groups', ['name' => 'Grup Utama']);
    }

    /**
     * Real bug caught during manual verification, not by a unit test:
     * CustomerIpPool's restrictOnDelete() FK only blocks a HARD delete,
     * never a soft one (soft-delete is just an UPDATE), so a soft-deleted
     * pool's id could otherwise still pass Rule::exists() and later crash
     * NetworkProfileGroupService with a null->name access.
     */
    public function test_a_soft_deleted_customer_ip_pool_is_rejected_on_create(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $pool->delete();

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/network-profile-groups', $this->payload($nas->id, $pool->id));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['customer_ip_pool_id']);
    }

    /**
     * The other half of the same bug: a group created validly, whose pool
     * is soft-deleted LATER (independently — the FK never blocks this),
     * must fail cleanly on the NEXT update attempt — even one that never
     * touches customer_ip_pool_id at all — rather than crash inside the
     * Service.
     */
    public function test_updating_an_unrelated_field_after_the_linked_pool_was_soft_deleted_fails_cleanly(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id]);
        $pool->delete();

        $response = $this->actingAs($this->admin($tenant))->putJson("/api/v1/network-profile-groups/{$group->id}", ['name' => 'Nama Baru Saja']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['customer_ip_pool_id']);
        $this->assertDatabaseHas('network_profile_groups', ['id' => $group->id, 'name' => $group->name]);
    }

    public function test_customer_ip_pool_from_the_same_nas_is_allowed(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/network-profile-groups', $this->payload($nas->id, $pool->id));

        $response->assertCreated();
    }

    public function test_invalid_type_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/network-profile-groups', $this->payload($nas->id, $pool->id, ['type' => 'not-a-real-type']));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['type']);
    }

    public function test_same_group_name_is_allowed_on_a_different_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $poolA = CustomerIpPool::factory()->create(['nas_id' => $nasA->id]);
        $poolB = CustomerIpPool::factory()->create(['nas_id' => $nasB->id]);
        NetworkProfileGroup::factory()->create(['nas_id' => $nasA->id, 'customer_ip_pool_id' => $poolA->id, 'name' => 'Grup Utama']);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/network-profile-groups', $this->payload($nasB->id, $poolB->id));

        $response->assertCreated();
    }

    public function test_same_group_name_on_the_same_nas_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id, 'name' => 'Grup Utama']);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/network-profile-groups', $this->payload($nas->id, $pool->id));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_admin_can_update_a_network_profile_group(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id, 'name' => 'Lama']);

        $response = $this->actingAs($this->admin($tenant))->putJson("/api/v1/network-profile-groups/{$group->id}", ['name' => 'Baru']);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Baru');
    }

    public function test_updating_to_a_pool_from_a_different_nas_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $poolA = CustomerIpPool::factory()->create(['nas_id' => $nasA->id]);
        $poolB = CustomerIpPool::factory()->create(['nas_id' => $nasB->id]);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nasA->id, 'customer_ip_pool_id' => $poolA->id]);

        $response = $this->actingAs($this->admin($tenant))->putJson("/api/v1/network-profile-groups/{$group->id}", ['customer_ip_pool_id' => $poolB->id]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['customer_ip_pool_id']);
    }

    public function test_admin_can_soft_delete_a_network_profile_group(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id]);

        $response = $this->actingAs($this->admin($tenant))->deleteJson("/api/v1/network-profile-groups/{$group->id}");

        $response->assertOk();
        $this->assertSoftDeleted('network_profile_groups', ['id' => $group->id]);
    }

    public function test_index_can_be_filtered_per_nas_and_type(): void
    {
        $tenant = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $poolA = CustomerIpPool::factory()->create(['nas_id' => $nasA->id]);
        $poolB = CustomerIpPool::factory()->create(['nas_id' => $nasB->id]);
        NetworkProfileGroup::factory()->create(['nas_id' => $nasA->id, 'customer_ip_pool_id' => $poolA->id, 'name' => 'Grup A', 'type' => 'ppp']);
        NetworkProfileGroup::factory()->create(['nas_id' => $nasB->id, 'customer_ip_pool_id' => $poolB->id, 'name' => 'Grup B', 'type' => 'hotspot']);

        $response = $this->actingAs($this->admin($tenant))->getJson("/api/v1/network-profile-groups?nas_id={$nasA->id}");
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Grup A');

        $response = $this->actingAs($this->admin($tenant))->getJson('/api/v1/network-profile-groups?type=hotspot');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Grup B');
    }

    public function test_network_profile_groups_from_another_tenant_are_not_visible(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenantA->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenantB->id]);
        $poolA = CustomerIpPool::factory()->create(['nas_id' => $nasA->id]);
        $poolB = CustomerIpPool::factory()->create(['nas_id' => $nasB->id]);
        NetworkProfileGroup::factory()->create(['nas_id' => $nasA->id, 'customer_ip_pool_id' => $poolA->id, 'name' => 'Tenant A Grup']);
        NetworkProfileGroup::factory()->create(['nas_id' => $nasB->id, 'customer_ip_pool_id' => $poolB->id, 'name' => 'Tenant B Grup']);

        $response = $this->actingAs($this->admin($tenantA))->getJson('/api/v1/network-profile-groups');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Tenant A Grup');
    }

    // --- RouterOS push dispatch -----------------------------------------

    public function test_create_dispatches_the_push_job(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/network-profile-groups', $this->payload($nas->id, $pool->id));

        $response->assertCreated();
        $groupId = $response->json('data.id');
        Bus::assertDispatched(PushNetworkProfileGroupToMikrotikJob::class, fn ($job) => $job->networkProfileGroupId === $groupId);
    }

    public function test_admin_can_trigger_a_manual_resync_for_a_failed_group(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id]);
        $group->markSyncFailed('router unreachable');

        Bus::fake();

        $response = $this->actingAs($this->admin($tenant))->postJson("/api/v1/network-profile-groups/{$group->id}/resync");

        $response->assertOk();
        $response->assertJsonPath('data.mikrotik_sync_status', 'pending');
        Bus::assertDispatched(PushNetworkProfileGroupToMikrotikJob::class, fn ($job) => $job->networkProfileGroupId === $group->id);
    }

    // --- radgroupreply ----------------------------------------------------

    public function test_creating_a_ppp_group_writes_the_established_3_attribute_radgroupreply_shape(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'Pool-PPP']);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/network-profile-groups', $this->payload($nas->id, $pool->id, ['type' => 'ppp']));
        $response->assertCreated();

        $groupName = 'boss-grup-profil-'.$response->json('data.id');
        $rows = DB::connection('radius')->table('radgroupreply')->where('groupname', $groupName)->orderBy('attribute')->get();

        $this->assertCount(3, $rows);
        $this->assertTrue($rows->contains(fn ($r) => $r->attribute === 'Service-Type' && $r->op === '=' && $r->value === 'Framed-User'));
        $this->assertTrue($rows->contains(fn ($r) => $r->attribute === 'Framed-Protocol' && $r->op === '=' && $r->value === 'PPP'));
        $this->assertTrue($rows->contains(fn ($r) => $r->attribute === 'Framed-Pool' && $r->op === ':=' && $r->value === 'Pool-PPP'));
    }

    public function test_creating_a_hotspot_group_writes_the_2_attribute_radgroupreply_shape(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'Pool-Hotspot']);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/network-profile-groups', $this->payload($nas->id, $pool->id, ['type' => 'hotspot']));
        $response->assertCreated();

        $groupName = 'boss-grup-profil-'.$response->json('data.id');
        $rows = DB::connection('radius')->table('radgroupreply')->where('groupname', $groupName)->orderBy('attribute')->get();

        $this->assertCount(2, $rows);
        $this->assertTrue($rows->contains(fn ($r) => $r->attribute === 'Service-Type' && $r->value === 'Login-User'));
        $this->assertTrue($rows->contains(fn ($r) => $r->attribute === 'Framed-Pool' && $r->value === 'Pool-Hotspot'));
    }

    public function test_updating_a_group_rewrites_radgroupreply_wholesale(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $poolOld = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'Pool-Lama']);
        $poolNew = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'Pool-Baru']);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $poolOld->id, 'type' => 'ppp']);
        $groupName = $group->radiusGroupName();
        $this->actingAs($this->admin($tenant));
        DB::connection('radius')->table('radgroupreply')->where('groupname', $groupName)->delete();
        DB::connection('radius')->table('radgroupreply')->insert(['groupname' => $groupName, 'attribute' => 'Framed-Pool', 'op' => ':=', 'value' => 'Pool-Lama']);

        $this->putJson("/api/v1/network-profile-groups/{$group->id}", ['customer_ip_pool_id' => $poolNew->id])->assertOk();

        $rows = DB::connection('radius')->table('radgroupreply')->where('groupname', $groupName)->get();
        $this->assertCount(3, $rows); // full ppp shape rewritten, not appended
        $this->assertTrue($rows->contains(fn ($r) => $r->attribute === 'Framed-Pool' && $r->value === 'Pool-Baru'));
        $this->assertFalse($rows->contains(fn ($r) => $r->value === 'Pool-Lama'));
    }

    public function test_deleting_a_group_removes_its_radgroupreply_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id]);
        $groupName = $group->radiusGroupName();

        $this->actingAs($this->admin($tenant))->deleteJson("/api/v1/network-profile-groups/{$group->id}")->assertOk();

        $this->assertSame(0, DB::connection('radius')->table('radgroupreply')->where('groupname', $groupName)->count());
    }
}
