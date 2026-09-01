<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\FiberNodeIndex;
use App\Models\FiberNode;
use App\Models\Odp;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FiberNodeIndexLivewireTest extends TestCase
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

    public function test_non_admin_tier_user_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->nonAdminStaff($tenant))
            ->test(FiberNodeIndex::class)
            ->assertForbidden();
    }

    public function test_lists_fiber_nodes_and_odps_together(): void
    {
        $tenant = Tenant::factory()->create();
        FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'otb', 'local_label' => 'OTB-1']);
        Odp::factory()->create(['tenant_id' => $tenant->id, 'name' => 'ODP-1']);

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeIndex::class)
            ->html();

        $this->assertStringContainsString('OTB-1', $html);
        $this->assertStringContainsString('ODP-1', $html);
    }

    public function test_filtering_by_node_type_odp_only_shows_odps(): void
    {
        $tenant = Tenant::factory()->create();
        FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'otb', 'local_label' => 'OTB-Only']);
        Odp::factory()->create(['tenant_id' => $tenant->id, 'name' => 'ODP-Only']);

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeIndex::class)
            ->set('nodeTypeFilter', 'odp')
            ->html();

        $this->assertStringNotContainsString('OTB-Only', $html);
        $this->assertStringContainsString('ODP-Only', $html);
    }

    public function test_admin_can_delete_a_fiber_node(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeIndex::class)
            ->call('deleteNode', $node->id);

        $this->assertSoftDeleted('fiber_nodes', ['id' => $node->id]);
    }
}
