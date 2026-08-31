<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\FiberNodeDetail;
use App\Models\FiberAccessory;
use App\Models\FiberNode;
use App\Models\Odp;
use App\Models\Splitter;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\FiberTopologyService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FiberNodeDetailLivewireTest extends TestCase
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

    public function test_renders_for_a_fiber_node_with_zero_children(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'otb', 'local_label' => 'OTB-Lone']);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $node])
            ->assertOk()
            ->assertSee('OTB-Lone')
            ->assertSee('Tidak ada percabangan.');
    }

    public function test_renders_for_a_fiber_node_with_one_child(): void
    {
        $tenant = Tenant::factory()->create();
        $otb = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'otb', 'local_label' => 'OTB-Parent']);
        $odc = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'odc', 'local_label' => 'ODC-Child', 'loss_in_db' => 1, 'loss_out_db' => 1]);

        app(FiberTopologyService::class)->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class, 'from_id' => $otb->id,
            'to_type' => FiberNode::class, 'to_id' => $odc->id,
            'total_cores' => 4, 'tube_count' => 1, 'cores_per_tube' => 4,
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $otb])
            ->assertOk()
            ->assertSee('OTB-Parent')
            ->assertSee('ODC-Child');
    }

    public function test_renders_for_a_fiber_node_with_many_children_as_cards_not_one_giant_diagram(): void
    {
        $tenant = Tenant::factory()->create();
        $odc = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'odc', 'local_label' => 'ODC-Hub', 'loss_in_db' => 1, 'loss_out_db' => 1]);
        $service = app(FiberTopologyService::class);

        $childLabels = [];

        for ($i = 1; $i <= 5; $i++) {
            $child = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'closure', 'local_label' => "Closure-{$i}"]);
            $childLabels[] = "Closure-{$i}";

            $service->createCable([
                'tenant_id' => $tenant->id,
                'from_type' => FiberNode::class, 'from_id' => $odc->id,
                'to_type' => FiberNode::class, 'to_id' => $child->id,
                'total_cores' => 2, 'tube_count' => 1, 'cores_per_tube' => 2,
            ]);
        }

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $odc])
            ->assertOk();

        foreach ($childLabels as $label) {
            $component->assertSee($label);
        }
    }

    public function test_renders_for_an_odp_target(): void
    {
        $tenant = Tenant::factory()->create();
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'code' => 'ODP-D1', 'name' => 'ODP Detail Test']);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['odp' => $odp])
            ->assertOk()
            ->assertSee('ODP-D1');
    }

    public function test_shows_a_warning_badge_when_measured_loss_differs_from_expected_by_more_than_2db(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'odc', 'loss_in_db' => 1, 'loss_out_db' => 1]);
        $splitter = Splitter::factory()->create(['owner_type' => FiberNode::class, 'owner_id' => $node->id]);
        FiberAccessory::factory()->create([
            'fiber_cable_id' => null,
            'splitter_id' => $splitter->id,
            'accessory_type' => 'splice_fusion',
            'expected_loss_db' => 0.15,
            'measured_loss_db' => 3.5,
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $node])
            ->assertOk()
            ->assertSee('periksa ulang');
    }

    public function test_does_not_show_a_warning_badge_when_measured_loss_is_close_to_expected(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'odc', 'loss_in_db' => 1, 'loss_out_db' => 1]);
        $splitter = Splitter::factory()->create(['owner_type' => FiberNode::class, 'owner_id' => $node->id]);
        FiberAccessory::factory()->create([
            'fiber_cable_id' => null,
            'splitter_id' => $splitter->id,
            'accessory_type' => 'splice_fusion',
            'expected_loss_db' => 0.15,
            'measured_loss_db' => 0.20,
        ]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $node])
            ->assertOk();

        $component->assertDontSee('periksa ulang');
    }

    public function test_non_admin_tier_user_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($user)
            ->test(FiberNodeDetail::class, ['fiber_node' => $node])
            ->assertForbidden();
    }
}
