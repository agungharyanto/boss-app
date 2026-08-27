<?php

namespace Tests\Feature\Network;

use App\Enums\MikrotikSyncStatus;
use App\Jobs\PushNetworkProfileGroupToMikrotikJob;
use App\Livewire\Network\NetworkProfileGroupIndex;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Models\NetworkProfileGroup;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class NetworkProfileGroupIndexLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

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

    public function test_creating_a_group_via_the_form(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('nasId', (string) $nas->id)
            ->set('name', 'Grup Utama')
            ->set('type', 'ppp')
            ->set('customerIpPoolId', (string) $pool->id)
            ->call('createGroup')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('network_profile_groups', ['nas_id' => $nas->id, 'name' => 'Grup Utama']);
    }

    public function test_changing_nas_in_the_create_form_resets_the_selected_pool(): void
    {
        $tenant = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $poolA = CustomerIpPool::factory()->create(['nas_id' => $nasA->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('nasId', (string) $nasA->id)
            ->set('customerIpPoolId', (string) $poolA->id)
            ->set('nasId', (string) $nasB->id)
            ->assertSet('customerIpPoolId', '');
    }

    public function test_pool_dropdown_only_lists_pools_belonging_to_the_selected_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);
        CustomerIpPool::factory()->create(['nas_id' => $nasA->id, 'name' => 'Pool Milik A']);
        CustomerIpPool::factory()->create(['nas_id' => $nasB->id, 'name' => 'Pool Milik B']);

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('showCreateForm', true)
            ->set('nasId', (string) $nasA->id)
            ->html();

        $this->assertStringContainsString('Pool Milik A', $html);
        $this->assertStringNotContainsString('Pool Milik B', $html);
    }

    public function test_customer_ip_pool_from_a_different_nas_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $poolFromNasB = CustomerIpPool::factory()->create(['nas_id' => $nasB->id]);

        // Directly forcing customerIpPoolId to a mismatched value —
        // bypassing the updatedNasId() reset a real user interaction would
        // trigger — to prove the SERVER-SIDE cross-check itself works,
        // not just the client-side reset convenience.
        $component = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('nasId', (string) $nasA->id)
            ->set('name', 'Grup Utama')
            ->set('customerIpPoolId', (string) $poolFromNasB->id)
            ->call('createGroup');

        $component->assertHasErrors('customerIpPoolId');
        $this->assertDatabaseMissing('network_profile_groups', ['name' => 'Grup Utama']);
    }

    /** Same real bug as NetworkProfileGroupApiTest — see that file's own docblock. */
    public function test_a_soft_deleted_customer_ip_pool_is_rejected_on_create(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $pool->delete();

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('nasId', (string) $nas->id)
            ->set('name', 'Grup Utama')
            ->set('customerIpPoolId', (string) $pool->id)
            ->call('createGroup');

        $component->assertHasErrors('customerIpPoolId');
        $this->assertDatabaseMissing('network_profile_groups', ['name' => 'Grup Utama']);
    }

    public function test_editing_after_the_linked_pool_was_soft_deleted_fails_cleanly(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id]);
        $pool->delete();

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->call('edit', $group->id)
            ->set('editName', 'Nama Baru Saja')
            ->call('updateGroup');

        $component->assertHasErrors('editCustomerIpPoolId');
        $this->assertDatabaseHas('network_profile_groups', ['id' => $group->id, 'name' => $group->name]);
    }

    public function test_same_group_name_on_the_same_nas_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id, 'name' => 'Grup Utama']);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('nasId', (string) $nas->id)
            ->set('name', 'Grup Utama')
            ->set('customerIpPoolId', (string) $pool->id)
            ->call('createGroup');

        $component->assertHasErrors('name');
    }

    public function test_editing_a_group(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id, 'name' => 'Lama']);

        Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->call('edit', $group->id)
            ->set('editName', 'Baru')
            ->call('updateGroup')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('network_profile_groups', ['id' => $group->id, 'name' => 'Baru']);
    }

    public function test_editing_to_a_pool_from_a_different_nas_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $poolA = CustomerIpPool::factory()->create(['nas_id' => $nasA->id]);
        $poolB = CustomerIpPool::factory()->create(['nas_id' => $nasB->id]);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nasA->id, 'customer_ip_pool_id' => $poolA->id]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->call('edit', $group->id)
            ->set('editCustomerIpPoolId', (string) $poolB->id)
            ->call('updateGroup');

        $component->assertHasErrors('editCustomerIpPoolId');
    }

    public function test_deleting_a_group_soft_deletes_it(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->call('deleteGroup', $group->id);

        $this->assertSoftDeleted('network_profile_groups', ['id' => $group->id]);
    }

    public function test_non_admin_tier_user_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('customer_service');

        Livewire::actingAs($user)->test(NetworkProfileGroupIndex::class)->assertForbidden();
    }

    // --- Auto-refresh (reused from v0.14.2.2) ---------------------------

    public function test_wire_poll_is_present_when_a_visible_row_is_pending(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id]); // defaults Pending

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->html();

        $this->assertStringContainsString('wire:poll.5s="$refresh"', $html);
    }

    public function test_wire_poll_is_absent_when_no_visible_row_is_pending(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id]);
        $group->markSynced();

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->html();

        $this->assertStringNotContainsString('wire:poll', $html);
    }

    public function test_muat_ulang_button_is_wired_to_refresh(): void
    {
        $tenant = Tenant::factory()->create();

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->html();

        $this->assertStringContainsString('wire:click="$refresh"', $html);
        $this->assertStringContainsString('Muat Ulang', $html);
    }

    public function test_sync_ulang_button_only_shows_for_a_failed_group(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $pending = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id, 'name' => 'Grup Pending']);
        $failed = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id, 'name' => 'Grup Gagal']);
        $failed->markSyncFailed('router unreachable');

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->html();

        $this->assertStringContainsString('resyncGroup('.$failed->id.')', $html);
        $this->assertStringNotContainsString('resyncGroup('.$pending->id.')', $html);
    }

    public function test_resync_group_re_dispatches_the_push_job(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id]);
        $group->markSyncFailed('router unreachable');

        Bus::fake();

        Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->call('resyncGroup', $group->id);

        Bus::assertDispatched(PushNetworkProfileGroupToMikrotikJob::class, fn ($job) => $job->networkProfileGroupId === $group->id);
        $this->assertSame(MikrotikSyncStatus::Pending, $group->fresh()->mikrotik_sync_status);
    }
}
