<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\FiberNodeDetail;
use App\Models\FiberAccessory;
use App\Models\FiberCable;
use App\Models\FiberNode;
use App\Models\Odp;
use App\Models\OltDevice;
use App\Models\OltModel;
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

    /**
     * @return array{0: FiberNode, 1: FiberNode, 2: FiberCable}
     */
    private function otbWithOutgoingCable(Tenant $tenant, int $portCount = 4): array
    {
        $otb = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'otb', 'local_label' => 'OTB-Sim', 'port_count' => $portCount]);
        $dest = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'closure', 'local_label' => 'Closure-Kaliwungu-1', 'port_count' => null]);

        $cable = app(FiberTopologyService::class)->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class, 'from_id' => $otb->id,
            'to_type' => FiberNode::class, 'to_id' => $dest->id,
            'total_cores' => 4, 'tube_count' => 2, 'cores_per_tube' => 2,
        ]);

        return [$otb, $dest, $cable];
    }

    public function test_port_simulation_renders_empty_and_occupied_ports_for_an_otb(): void
    {
        $tenant = Tenant::factory()->create();
        [$otb, $dest, $cable] = $this->otbWithOutgoingCable($tenant, 4);

        $firstCore = $cable->cores()->orderBy('tube_number')->orderBy('core_number_in_tube')->first();
        app(FiberTopologyService::class)->assignCorePort($firstCore, $otb, 1);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $otb->fresh()])
            ->assertOk()
            ->assertSee('Simulasi Port')
            ->assertSee('Closure-Kaliwungu-1')   // destination of the patched core
            ->assertSee('belum dipatch');         // the other 3 empty ports
    }

    public function test_port_simulation_section_is_absent_for_a_non_otb_node(): void
    {
        $tenant = Tenant::factory()->create();
        $odc = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'odc', 'local_label' => 'ODC-1', 'port_count' => null]);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $odc])
            ->assertOk()
            ->assertDontSee('Simulasi Port');
    }

    public function test_assign_port_rejects_a_number_above_the_otb_port_count(): void
    {
        $tenant = Tenant::factory()->create();
        [$otb, , $cable] = $this->otbWithOutgoingCable($tenant, 4);
        $core = $cable->cores()->first();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $otb->fresh()])
            ->set("portInputs.{$core->id}", '9')
            ->call('assignPort', $core->id)
            ->assertHasErrors("portInputs.{$core->id}");

        $this->assertNull($core->fresh()->port_number);
    }

    public function test_per_row_assign_to_an_occupied_port_auto_releases_the_previous_holder(): void
    {
        $tenant = Tenant::factory()->create();
        [$otb, , $cable] = $this->otbWithOutgoingCable($tenant, 4);
        $cores = $cable->cores()->orderBy('id')->get();

        app(FiberTopologyService::class)->assignCorePort($cores[0], $otb, 2);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $otb->fresh()])
            ->set("portInputs.{$cores[1]->id}", '2')
            ->call('assignPort', $cores[1]->id)
            ->assertHasNoErrors();

        $this->assertNull($cores[0]->fresh()->port_number);
        $this->assertSame(2, $cores[1]->fresh()->port_number);
    }

    public function test_bulk_save_all_or_nothing_when_two_rows_claim_one_port(): void
    {
        $tenant = Tenant::factory()->create();
        [$otb, , $cable] = $this->otbWithOutgoingCable($tenant, 6);
        $cores = $cable->cores()->orderBy('id')->get();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $otb->fresh()])
            ->set("portInputs.{$cores[0]->id}", '3')
            ->set("portInputs.{$cores[1]->id}", '3')
            ->set("portInputs.{$cores[2]->id}", '5')
            ->call('saveAllPorts')
            ->assertHasErrors(["portInputs.{$cores[0]->id}", "portInputs.{$cores[1]->id}"]);

        $this->assertNull($cores[0]->fresh()->port_number);
        $this->assertNull($cores[2]->fresh()->port_number, 'valid row must not be partial-saved');
    }

    public function test_bulk_save_persists_every_valid_row_at_once(): void
    {
        $tenant = Tenant::factory()->create();
        [$otb, , $cable] = $this->otbWithOutgoingCable($tenant, 6);
        $cores = $cable->cores()->orderBy('id')->get();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $otb->fresh()])
            ->set("portInputs.{$cores[0]->id}", '1')
            ->set("portInputs.{$cores[1]->id}", '2')
            ->set("portInputs.{$cores[2]->id}", '3')
            ->call('saveAllPorts')
            ->assertHasNoErrors();

        $this->assertSame(1, $cores[0]->fresh()->port_number);
        $this->assertSame(2, $cores[1]->fresh()->port_number);
        $this->assertSame(3, $cores[2]->fresh()->port_number);
    }

    public function test_a_core_can_be_marked_as_connecting_directly_to_an_olt(): void
    {
        $tenant = Tenant::factory()->create();
        [$otb, , $cable] = $this->otbWithOutgoingCable($tenant, 24);
        $core = $cable->cores()->first();

        $model = OltModel::factory()->create(['name' => 'C300']);
        $olt = OltDevice::factory()->create(['tenant_id' => $tenant->id, 'name' => 'ZTE-Kaliwungu', 'olt_model_id' => $model->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $otb->fresh()])
            ->set("portInputs.{$core->id}", '20')
            ->set("oltDeviceInputs.{$core->id}", (string) $olt->id)
            ->set("oltPonInputs.{$core->id}", 'PON 1')
            ->call('saveAllPorts')
            ->assertHasNoErrors()
            ->assertSee('OLT: ZTE-Kaliwungu - PON 1');

        $core->refresh();
        $this->assertSame($olt->id, $core->olt_device_id);
        $this->assertSame('PON 1', $core->olt_pon_port_label);
    }

    public function test_add_accessory_from_the_detail_page_persists_a_fiber_accessory(): void
    {
        $tenant = Tenant::factory()->create();
        [$otb, , $cable] = $this->otbWithOutgoingCable($tenant, 4);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $otb->fresh()])
            ->set('showAccessoryForm', true)
            ->set('accTargetKey', "cable#{$cable->id}")
            ->set('accType', 'connector')
            ->set('accMeasuredLoss', '0.3')
            ->call('addAccessory')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('fiber_accessories', [
            'fiber_cable_id' => $cable->id,
            'accessory_type' => 'connector',
            'measured_loss_db' => 0.3,
        ]);
    }

    public function test_core_connections_lihat_di_peta_link_is_per_cable_not_per_core(): void
    {
        $tenant = Tenant::factory()->create();
        // 4-core cable — a per-core link would render 4 "Lihat di peta"
        // links; a per-cable one renders exactly one.
        [$otb, , $cable] = $this->otbWithOutgoingCable($tenant, 4);

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $otb->fresh()])
            ->assertOk()
            ->assertSee('Koneksi Core')
            ->assertSee(route('web.fiber-topology-map.index', ['cable' => $cable->id]), false)
            ->assertDontSee(route('web.fiber-topology-map.index', ['core' => $cable->cores()->first()->id]), false)
            ->html();

        $this->assertSame(1, substr_count($html, 'Lihat di peta'), 'exactly one map link per cable');
        // all 4 cores still listed in the table
        $this->assertStringContainsString('T1/C1', $html);
        $this->assertStringContainsString('T2/C2', $html);
    }

    public function test_core_connections_link_is_hidden_when_an_endpoint_lacks_coordinates(): void
    {
        $tenant = Tenant::factory()->create();
        $otb = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'otb', 'latitude' => -6.2, 'longitude' => 106.8]);
        $dest = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'closure', 'latitude' => null, 'longitude' => null]);

        app(FiberTopologyService::class)->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class, 'from_id' => $otb->id,
            'to_type' => FiberNode::class, 'to_id' => $dest->id,
            'total_cores' => 2, 'tube_count' => 1, 'cores_per_tube' => 2,
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $otb->fresh()])
            ->assertOk()
            ->assertSee('koordinat kurang')
            ->assertDontSee('Lihat di peta');
    }

    public function test_add_accessory_requires_a_measured_loss(): void
    {
        $tenant = Tenant::factory()->create();
        [$otb, , $cable] = $this->otbWithOutgoingCable($tenant, 4);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $otb->fresh()])
            ->set('showAccessoryForm', true)
            ->set('accTargetKey', "cable#{$cable->id}")
            ->set('accType', 'connector')
            ->call('addAccessory')
            ->assertHasErrors('accMeasuredLoss');

        $this->assertDatabaseCount('fiber_accessories', 0);
    }
}
