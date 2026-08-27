<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\CustomerIpPoolIndex;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
