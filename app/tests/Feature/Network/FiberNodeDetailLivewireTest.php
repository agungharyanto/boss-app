<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\FiberNodeDetail;
use App\Models\FiberAccessory;
use App\Models\FiberCable;
use App\Models\FiberCore;
use App\Models\FiberNode;
use App\Models\Nas;
use App\Models\Odp;
use App\Models\OltDevice;
use App\Models\OltModel;
use App\Models\Splitter;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\FiberCoreSpliceService;
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

    public function test_koneksi_core_shows_the_patched_port_and_its_destination_for_an_otb(): void
    {
        // v0.16.1 Bagian C — "Simulasi Port" merged into "Koneksi Core":
        // a patched core carries a "Port N" badge + its destination.
        $tenant = Tenant::factory()->create();
        [$otb, $dest, $cable] = $this->otbWithOutgoingCable($tenant, 4);

        $firstCore = $cable->cores()->orderBy('tube_number')->orderBy('core_number_in_tube')->first();
        app(FiberTopologyService::class)->assignCorePort($firstCore, $otb, 1);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $otb->fresh()])
            ->assertOk()
            ->assertSee('Koneksi Core')
            ->assertDontSee('Simulasi Port')
            ->assertSee('Port 1')
            ->assertSee('Closure-Kaliwungu-1')   // destination of the patched core
            ->assertSee('Port terpakai: 1 / 4');
    }

    public function test_non_otb_node_shows_assign_core_to_core_not_the_port_form(): void
    {
        $tenant = Tenant::factory()->create();
        $odc = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'odc', 'local_label' => 'ODC-1', 'port_count' => null]);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $odc])
            ->assertOk()
            ->assertSee('Assign Core-to-Core')
            ->assertDontSee('Assign Port ke Core')
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

    /* ---- v0.16.1 Bagian D — Assign Core-to-Core ---- */

    /**
     * @return array{0: FiberNode, 1: FiberCable, 2: FiberCable}
     */
    private function closureWithTwoCables(Tenant $tenant): array
    {
        $closure = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'closure', 'port_count' => null]);
        $a = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'otb', 'port_count' => 8]);
        $b = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'odc', 'port_count' => null]);

        $topo = app(FiberTopologyService::class);
        $cableA = $topo->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class, 'from_id' => $a->id,
            'to_type' => FiberNode::class, 'to_id' => $closure->id,
            'total_cores' => 4, 'tube_count' => 2, 'cores_per_tube' => 2,
        ]);
        $cableB = $topo->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class, 'from_id' => $closure->id,
            'to_type' => FiberNode::class, 'to_id' => $b->id,
            'total_cores' => 4, 'tube_count' => 2, 'cores_per_tube' => 2,
        ]);

        return [$closure, $cableA, $cableB];
    }

    public function test_create_splice_from_the_detail_page_persists_a_row(): void
    {
        $tenant = Tenant::factory()->create();
        [$closure, $cableA, $cableB] = $this->closureWithTwoCables($tenant);
        $a = $cableA->cores()->first();
        $b = $cableB->cores()->first();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $closure->fresh()])
            ->set('spliceCableA', (string) $cableA->id)
            ->set('spliceCoreA', (string) $a->id)
            ->set('spliceCableB', (string) $cableB->id)
            ->set('spliceCoreB', (string) $b->id)
            ->set('spliceLoss', '0.12')
            ->call('createSplice')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('fiber_core_splices', [
            'splice_node_type' => FiberNode::class,
            'splice_node_id' => $closure->id,
            'from_fiber_core_id' => $a->id,
            'to_fiber_core_id' => $b->id,
        ]);
    }

    public function test_create_splice_rejects_the_same_cable_on_both_sides(): void
    {
        $tenant = Tenant::factory()->create();
        [$closure, $cableA] = $this->closureWithTwoCables($tenant);
        $cores = $cableA->cores()->orderBy('id')->get();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $closure->fresh()])
            ->set('spliceCableA', (string) $cableA->id)
            ->set('spliceCoreA', (string) $cores[0]->id)
            ->set('spliceCableB', (string) $cableA->id)
            ->set('spliceCoreB', (string) $cores[1]->id)
            ->call('createSplice')
            ->assertHasErrors('spliceCableB');

        $this->assertDatabaseCount('fiber_core_splices', 0);
    }

    public function test_remove_splice_from_the_detail_page(): void
    {
        $tenant = Tenant::factory()->create();
        [$closure, $cableA, $cableB] = $this->closureWithTwoCables($tenant);

        $splice = app(FiberCoreSpliceService::class)->createSplice(
            $closure->fresh(),
            $cableA->cores()->first(),
            $cableB->cores()->first(),
        );

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $closure->fresh()])
            ->call('removeSplice', $splice->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('fiber_core_splices', ['id' => $splice->id]);
    }

    public function test_splice_form_is_hidden_without_at_least_one_incoming_and_one_outgoing_cable(): void
    {
        $tenant = Tenant::factory()->create();
        $odc = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'odc', 'port_count' => null]);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $odc])
            ->assertSee('Assign Core-to-Core')
            ->assertSee('kabel MASUK dan satu kabel KELUAR')
            ->assertDontSee('Sambungkan');
    }

    /* ---------- v0.16.1 Revisi ---------- */

    public function test_revisi2_b_splice_dropdowns_are_scoped_by_direction(): void
    {
        $tenant = Tenant::factory()->create();
        // cableA runs a -> closure (closure is `to` = INCOMING),
        // cableB runs closure -> b (closure is `from` = OUTGOING).
        [$closure, $cableA, $cableB] = $this->closureWithTwoCables($tenant);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $closure->fresh()])
            ->assertViewHas('spliceIncomingCableOptions', fn ($o) => collect($o)->pluck('id')->all() === [$cableA->id])
            ->assertViewHas('spliceOutgoingCableOptions', fn ($o) => collect($o)->pluck('id')->all() === [$cableB->id])
            ->assertViewHas('canSplice', true)
            ->assertSee('Kabel Masuk')
            ->assertSee('Kabel Keluar');
    }

    public function test_revisi_b_splice_core_dropdown_uses_full_tube_and_core_colour_labels(): void
    {
        $tenant = Tenant::factory()->create();
        [$closure, $cableA] = $this->closureWithTwoCables($tenant);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $closure->fresh()])
            ->set('spliceCableA', (string) $cableA->id)
            ->assertSee('Tube 1 (Biru) / Core 1 (Biru)')
            ->assertSee('Tube 1 (Biru) / Core 2 (Orange)')
            ->assertDontSee('T1/C1 (Biru)')
            // v0.16.1 Revisi 4 A — "(N core tersedia)" hint
            ->assertSee('(4 core tersedia)');
    }

    public function test_revisi4_a_a_core_through_spliced_at_another_node_is_still_offered_here(): void
    {
        $tenant = Tenant::factory()->create();
        // closure <-(cableA)- otb ;  closure -(cableB)-> $b(odc)
        [$closure, $cableA, $cableB] = $this->closureWithTwoCables($tenant);
        $b = FiberNode::find($cableB->to_id); // odc — cableB is INCOMING here

        // give $b an outgoing cable so the splice form shows at all
        $topo = app(FiberTopologyService::class);
        $down = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'odc', 'port_count' => null]);
        $cableC = $topo->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class, 'from_id' => $b->id,
            'to_type' => FiberNode::class, 'to_id' => $down->id,
            'total_cores' => 4, 'tube_count' => 2, 'cores_per_tube' => 2,
        ]);

        // through-splice EVERY cableB core at the closure (as the `to_`/keluar side)
        foreach ($cableB->cores as $i => $bc) {
            app(FiberCoreSpliceService::class)
                ->createSplice($closure->fresh(), $cableA->cores[$i], $bc);
        }

        // at node $b, cableB is incoming — all 4 cores must still be
        // available even though they're all spliced at the closure.
        $component = Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $b->fresh()])
            ->set('spliceCableA', (string) $cableB->id)
            ->set('spliceCableB', (string) $cableC->id);

        $this->assertCount(4, $component->viewData('spliceCoreAOptions'));
        $component->assertSee('(4 core tersedia)');
    }

    public function test_revisi_c_delete_cable_removes_the_cable_and_its_cores(): void
    {
        $tenant = Tenant::factory()->create();
        [$otb, $dest, $cable] = $this->otbWithOutgoingCable($tenant, 4);
        $coreIds = $cable->cores()->pluck('id')->all();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $otb->fresh()])
            ->call('deleteCable', $cable->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('fiber_cables', ['id' => $cable->id]);
        $this->assertSame(0, FiberCore::whereIn('id', $coreIds)->count());
    }

    public function test_revisi2_a_delete_cable_is_blocked_while_it_has_an_active_splice_then_allowed_after_removal(): void
    {
        $tenant = Tenant::factory()->create();
        [$closure, $cableA, $cableB] = $this->closureWithTwoCables($tenant);

        $splice = app(FiberCoreSpliceService::class)->createSplice(
            $closure->fresh(),
            $cableA->cores()->first(),
            $cableB->cores()->first(),
        );

        // 1) blocked — clear message, splice + cable both stay
        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $closure->fresh()])
            ->call('deleteCable', $cableA->id)
            ->assertSee('splice core-to-core aktif');

        $this->assertDatabaseHas('fiber_cables', ['id' => $cableA->id]);
        $this->assertDatabaseHas('fiber_core_splices', ['id' => $splice->id]);

        // 2) remove the splice, then the cable deletes fine
        app(FiberCoreSpliceService::class)->deleteSplice($splice->fresh());

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $closure->fresh()])
            ->call('deleteCable', $cableA->id);

        $this->assertDatabaseMissing('fiber_cables', ['id' => $cableA->id]);
    }

    public function test_revisi_c_delete_cable_rejects_a_cable_that_does_not_touch_this_node(): void
    {
        $tenant = Tenant::factory()->create();
        [$otb] = $this->otbWithOutgoingCable($tenant, 4);
        [, , $strangerCable] = $this->otbWithOutgoingCable($tenant, 4);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $otb->fresh()])
            ->call('deleteCable', $strangerCable->id)
            ->assertForbidden();

        $this->assertDatabaseHas('fiber_cables', ['id' => $strangerCable->id]);
    }

    public function test_revisi2_c_no_swap_checkbox_column_in_the_assign_table(): void
    {
        $tenant = Tenant::factory()->create();
        [$otb] = $this->otbWithOutgoingCable($tenant, 8);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $otb->fresh()])
            ->assertDontSeeHtml('wire:click="toggleSwapSelection')
            ->assertSee('Tukar Port')                       // the modal trigger
            ->assertSeeHtml('wire:click="openSwapModal"');
    }

    public function test_revisi2_c_swap_antar_core_via_modal_exchanges_the_whole_port_assignment(): void
    {
        $tenant = Tenant::factory()->create();
        [$otb, $dest, $cable] = $this->otbWithOutgoingCable($tenant, 8);
        $olt = $this->oltDeviceForTenant($tenant, null);
        $cores = $cable->cores()->orderBy('tube_number')->orderBy('core_number_in_tube')->get();

        $topo = app(FiberTopologyService::class);
        $topo->assignCorePort($cores[0], $otb, 3, $olt->id, 'PON 1');
        $topo->assignCorePort($cores[1], $otb, 7);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $otb->fresh()])
            ->call('openSwapModal')
            ->call('chooseSwapMode', 'core')
            ->set('swapSourceCore', (string) $cores[0]->id)
            ->set('swapTargetCore', (string) $cores[1]->id)
            ->call('confirmSwapCores')
            ->assertHasNoErrors()
            ->assertSet('showSwapModal', false);

        // port_number AND the OLT/PON link move together
        $this->assertSame(7, $cores[0]->fresh()->port_number);
        $this->assertNull($cores[0]->fresh()->olt_device_id);
        $this->assertSame(3, $cores[1]->fresh()->port_number);
        $this->assertSame($olt->id, $cores[1]->fresh()->olt_device_id);
        $this->assertSame('PON 1', $cores[1]->fresh()->olt_pon_port_label);
    }

    public function test_revisi2_c_swap_antar_core_rejects_the_same_core_on_both_sides(): void
    {
        $tenant = Tenant::factory()->create();
        [$otb, $dest, $cable] = $this->otbWithOutgoingCable($tenant, 8);
        $core = $cable->cores()->first();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $otb->fresh()])
            ->call('openSwapModal')
            ->call('chooseSwapMode', 'core')
            ->set('swapSourceCore', (string) $core->id)
            ->set('swapTargetCore', (string) $core->id)
            ->call('confirmSwapCores')
            ->assertHasErrors('swapTargetCore');
    }

    public function test_revisi2_c_swap_antar_tube_via_modal_swaps_every_position_matched_core_in_one_go(): void
    {
        $tenant = Tenant::factory()->create();
        // tube_count 2, cores_per_tube 2 -> T1/C1, T1/C2, T2/C1, T2/C2
        [$otb, $dest, $cable] = $this->otbWithOutgoingCable($tenant, 8);
        $byPos = $cable->cores()->get()->keyBy(fn ($c) => "T{$c->tube_number}C{$c->core_number_in_tube}");

        $topo = app(FiberTopologyService::class);
        $topo->assignCorePort($byPos['T1C1'], $otb, 1);
        $topo->assignCorePort($byPos['T1C2'], $otb, 2);
        $topo->assignCorePort($byPos['T2C1'], $otb, 5);
        $topo->assignCorePort($byPos['T2C2'], $otb, 6);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $otb->fresh()])
            ->call('openSwapModal')
            ->call('chooseSwapMode', 'tube')
            ->set('swapCableId', (string) $cable->id)
            ->set('swapSourceTube', '1')
            ->set('swapTargetTube', '2')
            ->call('confirmSwapTubes')
            ->assertHasNoErrors()
            ->assertSet('showSwapModal', false);

        // position-matched exchange, nothing lost
        $this->assertSame(5, $byPos['T1C1']->fresh()->port_number);
        $this->assertSame(6, $byPos['T1C2']->fresh()->port_number);
        $this->assertSame(1, $byPos['T2C1']->fresh()->port_number);
        $this->assertSame(2, $byPos['T2C2']->fresh()->port_number);
    }

    public function test_revisi2_c_swap_antar_tube_rejects_the_same_tube_on_both_sides(): void
    {
        $tenant = Tenant::factory()->create();
        [$otb, $dest, $cable] = $this->otbWithOutgoingCable($tenant, 8);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $otb->fresh()])
            ->call('openSwapModal')
            ->call('chooseSwapMode', 'tube')
            ->set('swapCableId', (string) $cable->id)
            ->set('swapSourceTube', '1')
            ->set('swapTargetTube', '1')
            ->call('confirmSwapTubes')
            ->assertHasErrors('swapTargetTube');
    }

    public function test_revisi_e_port_search_filters_the_assign_table(): void
    {
        $tenant = Tenant::factory()->create();
        [$otb, $dest, $cable] = $this->otbWithOutgoingCable($tenant, 8);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $otb->fresh()])
            // tube 2 core colours are Biru/Orange again; tube colour "Orange" is tube #2
            ->assertSee('Tube 2 (Orange)')
            ->set('portSearch', 'Tube 1')
            ->assertSee('Tube 1 (Biru)')
            ->assertDontSee('Tube 2 (Orange)');
    }

    private function oltDeviceForTenant(Tenant $tenant, ?int $ponPortCount): OltDevice
    {
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        return OltDevice::factory()->create([
            'nas_id' => $nas->id,
            'pon_port_count' => $ponPortCount,
        ]);
    }

    public function test_revisi_f_pon_label_is_a_dropdown_when_the_selected_olt_has_a_pon_port_count(): void
    {
        $tenant = Tenant::factory()->create();
        [$otb, $dest, $cable] = $this->otbWithOutgoingCable($tenant, 8);
        $olt = $this->oltDeviceForTenant($tenant, 4);
        $core = $cable->cores()->first();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $otb->fresh()])
            ->set("oltDeviceInputs.{$core->id}", (string) $olt->id)
            ->assertSeeHtml('<option value="PON 1">')
            ->assertSeeHtml('<option value="PON 4">')
            ->assertDontSeeHtml('<option value="PON 5">');
    }

    public function test_revisi_f_pon_label_stays_free_text_when_the_olt_has_no_pon_port_count(): void
    {
        $tenant = Tenant::factory()->create();
        [$otb, $dest, $cable] = $this->otbWithOutgoingCable($tenant, 8);
        $olt = $this->oltDeviceForTenant($tenant, null);
        $core = $cable->cores()->first();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeDetail::class, ['fiber_node' => $otb->fresh()])
            ->set("oltDeviceInputs.{$core->id}", (string) $olt->id)
            ->assertSee('PON 1 / catatan')      // the free-text placeholder
            ->assertDontSeeHtml('<option value="PON 1">');
    }
}
