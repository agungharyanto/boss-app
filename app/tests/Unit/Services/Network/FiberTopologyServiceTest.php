<?php

namespace Tests\Unit\Services\Network;

use App\Enums\FiberNodeType;
use App\Models\Customer;
use App\Models\FiberCable;
use App\Models\FiberCore;
use App\Models\FiberNode;
use App\Models\Odp;
use App\Models\OdpPort;
use App\Models\Splitter;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\FiberTopologyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * DB-touching (creates real FiberCable/FiberCore/FiberNode rows) but no
 * HTTP layer involved — same "Unit" placement precedent as
 * Tests\Unit\Services\Installation\OdpLocatorServiceTest.
 */
class FiberTopologyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_cable_rejects_an_odd_total_cores(): void
    {
        $tenant = Tenant::factory()->create();
        $from = FiberNode::factory()->create(['tenant_id' => $tenant->id]);
        $to = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Jumlah core harus genap.');

        app(FiberTopologyService::class)->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class,
            'from_id' => $from->id,
            'to_type' => FiberNode::class,
            'to_id' => $to->id,
            'total_cores' => 5,
            'tube_count' => 1,
            'cores_per_tube' => 5,
        ]);
    }

    public function test_create_cable_rejects_when_tube_count_times_cores_per_tube_does_not_match_total_cores(): void
    {
        $tenant = Tenant::factory()->create();
        $from = FiberNode::factory()->create(['tenant_id' => $tenant->id]);
        $to = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        $this->expectException(InvalidArgumentException::class);

        app(FiberTopologyService::class)->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class,
            'from_id' => $from->id,
            'to_type' => FiberNode::class,
            'to_id' => $to->id,
            'total_cores' => 12,
            'tube_count' => 2,
            'cores_per_tube' => 4, // 2*4=8, not 12
        ]);
    }

    public function test_create_cable_generates_exactly_total_cores_fiber_core_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $from = FiberNode::factory()->create(['tenant_id' => $tenant->id]);
        $to = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        $cable = app(FiberTopologyService::class)->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class,
            'from_id' => $from->id,
            'to_type' => FiberNode::class,
            'to_id' => $to->id,
            'total_cores' => 12,
            'tube_count' => 2,
            'cores_per_tube' => 6,
        ]);

        $this->assertSame(12, $cable->cores()->count());
        $this->assertSame(6, $cable->cores()->where('tube_number', 1)->count());
        $this->assertSame(6, $cable->cores()->where('tube_number', 2)->count());
    }

    public function test_create_cable_assigns_colors_from_the_tia_eia_598_c_cycle(): void
    {
        $tenant = Tenant::factory()->create();
        $from = FiberNode::factory()->create(['tenant_id' => $tenant->id]);
        $to = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        $cable = app(FiberTopologyService::class)->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class,
            'from_id' => $from->id,
            'to_type' => FiberNode::class,
            'to_id' => $to->id,
            'total_cores' => 24,
            'tube_count' => 2,
            'cores_per_tube' => 12,
        ]);

        $tube1Core1 = $cable->cores()->where(['tube_number' => 1, 'core_number_in_tube' => 1])->firstOrFail();
        $tube2Core1 = $cable->cores()->where(['tube_number' => 2, 'core_number_in_tube' => 1])->firstOrFail();
        $tube1Core12 = $cable->cores()->where(['tube_number' => 1, 'core_number_in_tube' => 12])->firstOrFail();

        $this->assertSame('Biru', $tube1Core1->tube_color);
        $this->assertSame('Orange', $tube2Core1->tube_color);
        $this->assertSame('Biru', $tube1Core1->core_color);
        $this->assertSame('Toska', $tube1Core12->core_color);
    }

    public function test_loss_is_required_for_a_fiber_node_of_type_odc(): void
    {
        $service = app(FiberTopologyService::class);
        $target = new FiberNode(['node_type' => FiberNodeType::Odc]);

        $this->assertTrue($service->isLossRequired($target));
    }

    public function test_loss_is_not_required_for_otb_or_closure(): void
    {
        $service = app(FiberTopologyService::class);

        $this->assertFalse($service->isLossRequired(new FiberNode(['node_type' => FiberNodeType::Otb])));
        $this->assertFalse($service->isLossRequired(new FiberNode(['node_type' => FiberNodeType::Closure])));
    }

    /**
     * The "ODP" half of "loss wajib untuk ODC/ODP" — exercised directly
     * against the Service with a real Odp instance, since (per
     * docs/ROADMAP.md's v0.16.0 Langkah 2 notes) no dedicated Odp
     * FormRequest is built this Langkah — StoreOdpRequest/UpdateOdpRequest
     * (v0.5.0's existing registration flow) are deliberately left
     * untouched, per the explicit "don't disturb existing flow"
     * instruction. A future splice-data-entry form (Langkah 3+) would call
     * this exact same predicate.
     */
    public function test_loss_is_always_required_for_an_odp_regardless_of_any_field(): void
    {
        $service = app(FiberTopologyService::class);

        $this->assertTrue($service->isLossRequired(new Odp));
    }

    public function test_create_node_with_attachments_writes_node_photos_and_splitter_together(): void
    {
        Storage::fake('local');
        $tenant = Tenant::factory()->create();

        $node = app(FiberTopologyService::class)->createNodeWithAttachments(
            ['tenant_id' => $tenant->id, 'node_type' => 'odc', 'local_label' => 'ODC-1', 'loss_in_db' => 1.0, 'loss_out_db' => 1.0],
            [UploadedFile::fake()->create('a.jpg', 50, 'image/jpeg')],
            ['ratio' => '1:8', 'model' => null],
        );

        $this->assertDatabaseHas('fiber_nodes', ['id' => $node->id, 'local_label' => 'ODC-1']);
        $this->assertDatabaseHas('fiber_node_photos', ['owner_type' => FiberNode::class, 'owner_id' => $node->id]);
        $this->assertDatabaseHas('splitters', ['owner_id' => $node->id, 'ratio' => '1:8']);
    }

    public function test_attach_splitter_is_a_no_op_for_a_blank_ratio(): void
    {
        $tenant = Tenant::factory()->create();
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id]);

        $this->assertNull(app(FiberTopologyService::class)->attachSplitter($odp, ['ratio' => '  ', 'model' => 'x']));
        $this->assertNull(app(FiberTopologyService::class)->attachSplitter($odp, null));
        $this->assertSame(0, Splitter::count());
    }

    public function test_override_core_color_sets_and_clears(): void
    {
        $tenant = Tenant::factory()->create();
        $cable = FiberCable::factory()->create(['tenant_id' => $tenant->id]);
        $core = FiberCore::factory()->create(['fiber_cable_id' => $cable->id, 'core_color' => 'Biru']);

        $service = app(FiberTopologyService::class);

        $service->overrideCoreColor($core, 'Tube X', 'Core Y');
        $this->assertSame('Tube X', $core->refresh()->tube_color);
        $this->assertSame('Core Y', $core->core_color);

        $service->overrideCoreColor($core, '', '');
        $this->assertNull($core->refresh()->tube_color);
        $this->assertNull($core->core_color);
    }

    public function test_cable_target_candidates_excludes_self_and_existing_children(): void
    {
        $tenant = Tenant::factory()->create();
        $source = FiberNode::factory()->create(['tenant_id' => $tenant->id]);
        $child = Odp::factory()->create(['tenant_id' => $tenant->id]);
        $free = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        FiberCable::factory()->create([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class, 'from_id' => $source->id,
            'to_type' => Odp::class, 'to_id' => $child->id,
        ]);

        $keys = collect(app(FiberTopologyService::class)->cableTargetCandidates($source))
            ->map(fn ($c) => $c['type'].'#'.$c['id'])
            ->all();

        $this->assertContains(FiberNode::class.'#'.$free->id, $keys);
        $this->assertNotContains(Odp::class.'#'.$child->id, $keys);
        $this->assertNotContains(FiberNode::class.'#'.$source->id, $keys);
    }

    public function test_map_reference_points_returns_only_coordinate_bearing_points_and_can_exclude_one(): void
    {
        $tenant = Tenant::factory()->create();
        $withCoords = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'latitude' => -6.9, 'longitude' => 109.6]);
        $noCoords = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'latitude' => null, 'longitude' => null]);

        $points = app(FiberTopologyService::class)->mapReferencePoints($withCoords);
        $ids = collect($points)->where('type', 'fiber_node')->pluck('id')->all();

        $this->assertNotContains($withCoords->id, $ids);
        $this->assertNotContains($noCoords->id, $ids);
    }

    /**
     * @return array{0: FiberNode, 1: FiberCable}
     */
    private function otbCable(int $portCount = 4): array
    {
        $tenant = Tenant::factory()->create();
        $otb = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'otb', 'port_count' => $portCount]);
        $dest = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'closure', 'port_count' => null]);

        $cable = app(FiberTopologyService::class)->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class, 'from_id' => $otb->id,
            'to_type' => FiberNode::class, 'to_id' => $dest->id,
            'total_cores' => 4, 'tube_count' => 2, 'cores_per_tube' => 2,
        ]);

        return [$otb, $cable];
    }

    public function test_assign_core_port_rejects_a_number_above_port_count(): void
    {
        [$otb, $cable] = $this->otbCable(4);

        $this->expectException(InvalidArgumentException::class);
        app(FiberTopologyService::class)->assignCorePort($cable->cores()->first(), $otb, 5);
    }

    public function test_assign_core_port_auto_releases_the_previous_holder_on_a_per_row_reassign(): void
    {
        // v0.16.0 Langkah 7 — per-row assign to a port another core holds
        // is NOT a conflict; the previous holder is auto-released, and
        // both changes are audit-logged.
        [$otb, $cable] = $this->otbCable(4);
        $cores = $cable->cores()->orderBy('id')->get();
        $service = app(FiberTopologyService::class);

        $service->assignCorePort($cores[0], $otb, 3);
        $service->assignCorePort($cores[1], $otb, 3);

        $this->assertNull($cores[0]->fresh()->port_number);
        $this->assertSame(3, $cores[1]->fresh()->port_number);
        $this->assertDatabaseHas('fiber_core_port_logs', ['fiber_core_id' => $cores[0]->id, 'old_port_number' => 3, 'new_port_number' => null]);
        $this->assertDatabaseHas('fiber_core_port_logs', ['fiber_core_id' => $cores[1]->id, 'new_port_number' => 3]);
    }

    public function test_assign_core_port_moving_a_core_to_a_new_port_is_not_a_self_conflict(): void
    {
        [$otb, $cable] = $this->otbCable(4);
        $core = $cable->cores()->first();
        $service = app(FiberTopologyService::class);

        $service->assignCorePort($core, $otb, 2);
        $service->assignCorePort($core, $otb, 4); // same row, new number

        $this->assertSame(4, $core->fresh()->port_number);
    }

    public function test_bulk_save_rejects_two_different_rows_claiming_one_port_and_saves_nothing(): void
    {
        [$otb, $cable] = $this->otbCable(6);
        $cores = $cable->cores()->orderBy('id')->get();

        $errors = app(FiberTopologyService::class)->assignCorePorts($otb, [
            $cores[0]->id => ['port' => '3'],
            $cores[1]->id => ['port' => '3'],
            $cores[2]->id => ['port' => '5'],
        ]);

        $this->assertArrayHasKey($cores[0]->id, $errors);
        $this->assertArrayHasKey($cores[1]->id, $errors);
        $this->assertNull($cores[0]->fresh()->port_number);
        $this->assertNull($cores[2]->fresh()->port_number, 'a valid row must NOT be partial-saved when another row fails');
    }

    public function test_bulk_save_applies_a_valid_swap_across_ports_in_one_submit(): void
    {
        [$otb, $cable] = $this->otbCable(6);
        $cores = $cable->cores()->orderBy('id')->get();
        $service = app(FiberTopologyService::class);

        $service->assignCorePort($cores[0], $otb, 1);
        $service->assignCorePort($cores[1], $otb, 2);

        // core0 -> 2 (which core1 is vacating), core1 -> 1
        $errors = $service->assignCorePorts($otb, [
            $cores[0]->id => ['port' => '2'],
            $cores[1]->id => ['port' => '1'],
        ]);

        $this->assertSame([], $errors);
        $this->assertSame(2, $cores[0]->fresh()->port_number);
        $this->assertSame(1, $cores[1]->fresh()->port_number);
    }

    public function test_assign_core_port_clears_the_assignment_when_null(): void
    {
        [$otb, $cable] = $this->otbCable(4);
        $core = $cable->cores()->first();
        $service = app(FiberTopologyService::class);

        $service->assignCorePort($core, $otb, 2);
        $this->assertSame(2, $core->fresh()->port_number);

        $service->assignCorePort($core, $otb, null);
        $this->assertNull($core->fresh()->port_number);
    }

    public function test_assign_core_port_rejects_a_core_not_originating_from_that_otb(): void
    {
        [$otb, $cable] = $this->otbCable(4);
        $otherOtb = FiberNode::factory()->create(['node_type' => 'otb', 'port_count' => 8]);

        $this->expectException(InvalidArgumentException::class);
        app(FiberTopologyService::class)->assignCorePort($cable->cores()->first(), $otherOtb, 1);
    }

    public function test_otb_port_simulation_shape_mixes_empty_and_occupied_rows(): void
    {
        [$otb, $cable] = $this->otbCable(3);
        $service = app(FiberTopologyService::class);
        $service->assignCorePort($cable->cores()->first(), $otb, 2);

        $sim = $service->otbPortSimulation($otb);

        $this->assertCount(3, $sim);
        $this->assertNull($sim[0]['core']);          // port 1 empty
        $this->assertNotNull($sim[1]['core']);       // port 2 occupied
        $this->assertSame(2, $sim[1]['port']);
        $this->assertNull($sim[2]['core']);          // port 3 empty
    }

    public function test_topology_map_customers_only_returns_customers_with_coordinates(): void
    {
        $tenant = Tenant::factory()->create();
        Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Budi', 'address' => 'Jl. Mawar 1', 'latitude' => -6.2, 'longitude' => 106.8]);
        Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sinta', 'latitude' => null, 'longitude' => null]);

        $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id]));

        $rows = app(FiberTopologyService::class)->topologyMapCustomers();

        $this->assertCount(1, $rows);
        $this->assertSame('Budi', $rows[0]['name']);
        $this->assertSame('Jl. Mawar 1', $rows[0]['address']);
        $this->assertArrayHasKey('status', $rows[0]);
    }

    public function test_default_map_layers_are_the_four_fiber_node_types_only(): void
    {
        $this->assertSame(['otb', 'closure', 'odc', 'odp'], FiberTopologyService::DEFAULT_MAP_LAYERS);
        $this->assertNotContains('cable', FiberTopologyService::DEFAULT_MAP_LAYERS);
        $this->assertNotContains('customer', FiberTopologyService::DEFAULT_MAP_LAYERS);
    }

    public function test_build_topology_kml_includes_only_the_selected_categories(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id]));

        $otb = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'otb', 'local_label' => 'OTB-A', 'latitude' => -6.20, 'longitude' => 106.80]);
        $closure = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'closure', 'local_label' => 'CLO-B', 'latitude' => -6.21, 'longitude' => 106.81]);
        app(FiberTopologyService::class)->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class, 'from_id' => $otb->id,
            'to_type' => FiberNode::class, 'to_id' => $closure->id,
            'total_cores' => 2, 'tube_count' => 1, 'cores_per_tube' => 2,
        ]);
        Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Wati', 'latitude' => -6.22, 'longitude' => 106.82]);

        $service = app(FiberTopologyService::class);

        $otbOnly = $service->buildTopologyKml(['otb']);
        $this->assertStringContainsString('OTB-A', $otbOnly);
        $this->assertStringNotContainsString('CLO-B', $otbOnly);
        $this->assertStringNotContainsString('Pelanggan: Wati', $otbOnly);
        $this->assertStringNotContainsString('<LineString>', $otbOnly);

        $cableOnly = $service->buildTopologyKml(['cable']);
        $this->assertStringContainsString('<LineString>', $cableOnly);
        $this->assertStringNotContainsString('<Point>', $cableOnly);

        $customerOnly = $service->buildTopologyKml(['customer']);
        $this->assertStringContainsString('Pelanggan: Wati', $customerOnly);
        $this->assertStringNotContainsString('OTB-A', $customerOnly);

        $none = $service->buildTopologyKml([]);
        $this->assertStringNotContainsString('<Placemark>', $none);

        $all = $service->buildTopologyKml();
        $this->assertStringContainsString('OTB-A', $all);
        $this->assertStringContainsString('CLO-B', $all);
        $this->assertStringContainsString('Pelanggan: Wati', $all);
        $this->assertStringContainsString('<LineString>', $all);
    }

    public function test_capacity_zone_thresholds_match_the_progress_bar_partial(): void
    {
        $service = app(FiberTopologyService::class);

        $this->assertSame('longgar', $service->capacityZone(0)['label']);
        $this->assertSame('longgar', $service->capacityZone(59)['label']);
        $this->assertSame('hampir penuh', $service->capacityZone(60)['label']);
        $this->assertSame('hampir penuh', $service->capacityZone(80)['label']);
        $this->assertSame('penuh', $service->capacityZone(81)['label']);
        $this->assertSame('kapasitas tidak diketahui', $service->capacityZone(null)['label']);
    }

    public function test_odp_capacities_and_map_markers_share_the_same_figure(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id]));

        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'code' => 'ODP-CAP', 'latitude' => -6.2, 'longitude' => 106.8, 'total_ports' => 8]);
        OdpPort::factory()->forOdp($odp)->used()->count(7)->create(); // 88% -> penuh

        $service = app(FiberTopologyService::class);

        $cap = $service->odpCapacities()->get($odp->id);
        $this->assertSame(7, $cap['used']);
        $this->assertSame(8, $cap['total']);
        $this->assertSame(88, $cap['percent']);
        $this->assertSame('penuh', $cap['zone_label']);

        $marker = collect($service->topologyMapMarkers())->firstWhere('id', $odp->id);
        $this->assertSame('odp', $marker['node_type']);
        $this->assertSame(7, $marker['capacity']['used']);
        $this->assertSame(8, $marker['capacity']['total']);
        $this->assertSame('penuh', $marker['capacity']['zone_label']);

        // capacityReport() still exposes the same used/total for this ODP
        $reportRow = collect($service->capacityReport()['odps'])->firstWhere('id', $odp->id);
        $this->assertSame(7, $reportRow->used);
        $this->assertSame(88, $reportRow->percent);
    }

    public function test_odp_marker_capacity_is_null_when_total_ports_is_zero(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id]));

        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'latitude' => -6.2, 'longitude' => 106.8, 'total_ports' => 0]);

        $marker = collect(app(FiberTopologyService::class)->topologyMapMarkers())->firstWhere('id', $odp->id);
        $this->assertNull($marker['capacity']['percent']);
        $this->assertSame('kapasitas tidak diketahui', $marker['capacity']['zone_label']);
    }
}
