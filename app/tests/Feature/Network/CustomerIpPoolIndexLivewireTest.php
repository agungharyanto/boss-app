<?php

namespace Tests\Feature\Network;

use App\Enums\MikrotikSyncStatus;
use App\Jobs\PushCustomerIpPoolToMikrotikJob;
use App\Livewire\Network\CustomerIpPoolIndex;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerIpPoolIndexLivewireTest extends TestCase
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

    public function test_creating_a_pool_via_the_form(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerIpPoolIndex::class)
            ->set('nasId', (string) $nas->id)
            ->set('name', 'Pool Utama')
            ->set('networkAddress', '192.168.10.0/24')
            ->set('gatewayIp', '192.168.10.1')
            ->set('rangeStart', '192.168.10.10')
            ->set('rangeEnd', '192.168.10.200')
            ->call('createPool')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customer_ip_pools', [
            'nas_id' => $nas->id,
            'name' => 'Pool Utama',
            'network_address' => '192.168.10.0/24',
        ]);
    }

    public function test_range_end_before_range_start_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(CustomerIpPoolIndex::class)
            ->set('nasId', (string) $nas->id)
            ->set('name', 'Invalid')
            ->set('networkAddress', '192.168.10.0/24')
            ->set('gatewayIp', '192.168.10.1')
            ->set('rangeStart', '192.168.10.200')
            ->set('rangeEnd', '192.168.10.10')
            ->call('createPool');

        $component->assertHasErrors('rangeEnd');
        $this->assertDatabaseMissing('customer_ip_pools', ['name' => 'Invalid']);
    }

    public function test_gateway_outside_network_address_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(CustomerIpPoolIndex::class)
            ->set('nasId', (string) $nas->id)
            ->set('name', 'Invalid')
            ->set('networkAddress', '192.168.10.0/24')
            ->set('gatewayIp', '10.0.0.1')
            ->set('rangeStart', '192.168.10.10')
            ->set('rangeEnd', '192.168.10.200')
            ->call('createPool');

        $component->assertHasErrors('gatewayIp');
        $this->assertDatabaseMissing('customer_ip_pools', ['name' => 'Invalid']);
    }

    public function test_overlapping_range_on_the_same_nas_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        CustomerIpPool::factory()->create([
            'nas_id' => $nas->id,
            'network_address' => '192.168.10.0/24',
            'gateway_ip' => '192.168.10.1',
            'range_start' => '192.168.10.10',
            'range_end' => '192.168.10.200',
        ]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(CustomerIpPoolIndex::class)
            ->set('nasId', (string) $nas->id)
            ->set('name', 'Pool Kedua')
            ->set('networkAddress', '192.168.10.0/24')
            ->set('gatewayIp', '192.168.10.1')
            ->set('rangeStart', '192.168.10.150')
            ->set('rangeEnd', '192.168.10.250')
            ->call('createPool');

        $component->assertHasErrors('rangeEnd');
        $this->assertDatabaseMissing('customer_ip_pools', ['name' => 'Pool Kedua']);
    }

    /** Same range colliding on a DIFFERENT NAS must be allowed. */
    public function test_identical_range_on_a_different_nas_is_allowed(): void
    {
        $tenant = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);
        CustomerIpPool::factory()->create([
            'nas_id' => $nasA->id,
            'network_address' => '192.168.10.0/24',
            'gateway_ip' => '192.168.10.1',
            'range_start' => '192.168.10.10',
            'range_end' => '192.168.10.200',
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerIpPoolIndex::class)
            ->set('nasId', (string) $nasB->id)
            ->set('name', 'Pool NAS B')
            ->set('networkAddress', '192.168.10.0/24')
            ->set('gatewayIp', '192.168.10.1')
            ->set('rangeStart', '192.168.10.10')
            ->set('rangeEnd', '192.168.10.200')
            ->call('createPool')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customer_ip_pools', ['nas_id' => $nasB->id, 'name' => 'Pool NAS B']);
    }

    /** name unique PER NAS — same name on a different NAS must be allowed. */
    public function test_same_pool_name_is_allowed_on_a_different_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);
        CustomerIpPool::factory()->create(['nas_id' => $nasA->id, 'name' => 'Pool Utama']);

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerIpPoolIndex::class)
            ->set('nasId', (string) $nasB->id)
            ->set('name', 'Pool Utama')
            ->set('networkAddress', '192.168.20.0/24')
            ->set('gatewayIp', '192.168.20.1')
            ->set('rangeStart', '192.168.20.10')
            ->set('rangeEnd', '192.168.20.200')
            ->call('createPool')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customer_ip_pools', ['nas_id' => $nasB->id, 'name' => 'Pool Utama']);
    }

    public function test_same_pool_name_on_the_same_nas_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'Pool Utama']);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(CustomerIpPoolIndex::class)
            ->set('nasId', (string) $nas->id)
            ->set('name', 'Pool Utama')
            ->set('networkAddress', '192.168.20.0/24')
            ->set('gatewayIp', '192.168.20.1')
            ->set('rangeStart', '192.168.20.10')
            ->set('rangeEnd', '192.168.20.200')
            ->call('createPool');

        $component->assertHasErrors('name');
    }

    public function test_editing_a_pool(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'Lama']);

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerIpPoolIndex::class)
            ->call('edit', $pool->id)
            ->set('editName', 'Baru')
            ->call('updatePool')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customer_ip_pools', ['id' => $pool->id, 'name' => 'Baru']);
    }

    public function test_editing_a_pool_to_overlap_a_sibling_on_the_same_nas_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        CustomerIpPool::factory()->create([
            'nas_id' => $nas->id,
            'name' => 'Pool A',
            'network_address' => '192.168.10.0/24',
            'gateway_ip' => '192.168.10.1',
            'range_start' => '192.168.10.10',
            'range_end' => '192.168.10.100',
        ]);
        $poolB = CustomerIpPool::factory()->create([
            'nas_id' => $nas->id,
            'name' => 'Pool B',
            'network_address' => '192.168.10.0/24',
            'gateway_ip' => '192.168.10.1',
            'range_start' => '192.168.10.101',
            'range_end' => '192.168.10.200',
        ]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(CustomerIpPoolIndex::class)
            ->call('edit', $poolB->id)
            ->set('editRangeStart', '192.168.10.50')
            ->call('updatePool');

        $component->assertHasErrors('editRangeEnd');
        $this->assertDatabaseHas('customer_ip_pools', ['id' => $poolB->id, 'range_start' => '192.168.10.101']);
    }

    public function test_updating_a_pool_without_changing_its_range_does_not_reject_itself_as_an_overlap(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create([
            'nas_id' => $nas->id,
            'name' => 'Pool Utama',
            'network_address' => '192.168.10.0/24',
            'gateway_ip' => '192.168.10.1',
            'range_start' => '192.168.10.10',
            'range_end' => '192.168.10.200',
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerIpPoolIndex::class)
            ->call('edit', $pool->id)
            ->set('editName', 'Pool Utama Diperbarui')
            ->call('updatePool')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customer_ip_pools', ['id' => $pool->id, 'name' => 'Pool Utama Diperbarui']);
    }

    public function test_deleting_a_pool_soft_deletes_it(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerIpPoolIndex::class)
            ->call('deletePool', $pool->id);

        $this->assertSoftDeleted('customer_ip_pools', ['id' => $pool->id]);
    }

    public function test_filtering_by_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);
        // Names deliberately don't share a "Pool X" prefix with the page's
        // own "+ Pool Baru" button text — an earlier version of this test
        // used "Pool A"/"Pool B" and assertDontSee('Pool B') failed for the
        // WRONG reason: it matched the substring "Pool B" inside "+ Pool
        // Baru", not an actual leaked row (confirmed by inspecting the
        // real rendered HTML before renaming these fixtures).
        CustomerIpPool::factory()->create(['nas_id' => $nasA->id, 'name' => 'Kutub Utara']);
        CustomerIpPool::factory()->create(['nas_id' => $nasB->id, 'name' => 'Kutub Selatan']);

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerIpPoolIndex::class)
            ->set('filterNasId', (string) $nasA->id)
            ->assertSee('Kutub Utara')
            ->assertDontSee('Kutub Selatan');
    }

    public function test_non_admin_tier_user_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('customer_service');

        Livewire::actingAs($user)->test(CustomerIpPoolIndex::class)->assertForbidden();
    }

    /** v0.14.2.1 — "Sync Ulang" only renders for a Gagal row, never Pending/Tersinkron. */
    public function test_sync_ulang_button_only_shows_for_a_failed_pool(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pending = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'Pool Pending']);
        $failed = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'Pool Gagal']);
        $failed->markSyncFailed('router unreachable');

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(CustomerIpPoolIndex::class)
            ->html();

        $this->assertStringContainsString('resyncPool('.$failed->id.')', $html);
        $this->assertStringNotContainsString('resyncPool('.$pending->id.')', $html);
    }

    public function test_resync_pool_re_dispatches_the_push_job(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $pool->markSyncFailed('router unreachable');

        Bus::fake();

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerIpPoolIndex::class)
            ->call('resyncPool', $pool->id);

        Bus::assertDispatched(PushCustomerIpPoolToMikrotikJob::class, fn ($job) => $job->customerIpPoolId === $pool->id);
        $this->assertSame(MikrotikSyncStatus::Pending, $pool->fresh()->mikrotik_sync_status);
    }

    /**
     * v0.14.2.2 — auto-refresh. wire:poll only appears in the rendered
     * HTML while a visible row is still Pending — this is what makes
     * Livewire's own conditional-polling mechanism actually stop once
     * nothing is left to wait for.
     */
    public function test_wire_poll_is_present_when_a_visible_row_is_pending(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        CustomerIpPool::factory()->create(['nas_id' => $nas->id]); // defaults to Pending

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(CustomerIpPoolIndex::class)
            ->html();

        $this->assertStringContainsString('wire:poll.5s="$refresh"', $html);
    }

    public function test_wire_poll_is_absent_when_no_visible_row_is_pending(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $synced = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $synced->markSynced();
        $failed = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $failed->markSyncFailed('timeout');

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(CustomerIpPoolIndex::class)
            ->html();

        $this->assertStringNotContainsString('wire:poll', $html);
    }

    public function test_wire_poll_stops_once_the_last_pending_row_resolves(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]); // Pending

        $component = Livewire::actingAs($this->admin($tenant))->test(CustomerIpPoolIndex::class);
        $this->assertStringContainsString('wire:poll.5s="$refresh"', $component->html());

        // Simulate the queued Job finishing in the background, exactly
        // like PushCustomerIpPoolToMikrotikJob::handle() would — this
        // component never touches the row itself, it only re-queries.
        $pool->markSynced();

        $component->call('$refresh');

        $this->assertStringNotContainsString('wire:poll', $component->html());
    }

    /** The "Muat Ulang" button issues a plain Livewire AJAX $refresh — never a full page/URL navigation. */
    public function test_muat_ulang_button_is_wired_to_refresh_not_a_page_reload(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        CustomerIpPool::factory()->create(['nas_id' => $nas->id]);

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(CustomerIpPoolIndex::class)
            ->html();

        $this->assertStringContainsString('wire:click="$refresh"', $html);
        $this->assertStringContainsString('Muat Ulang', $html);
    }

    public function test_manual_refresh_picks_up_a_status_change_made_outside_the_component(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'Pool Refresh']);

        $component = Livewire::actingAs($this->admin($tenant))->test(CustomerIpPoolIndex::class);
        $component->assertSee('Pending');

        $pool->markSynced();

        $component->call('$refresh')->assertSee('Tersinkron');
    }
}
