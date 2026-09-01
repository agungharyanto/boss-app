<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\FiberTopologyMap;
use App\Models\Customer;
use App\Models\FiberCable;
use App\Models\FiberCableWaypoint;
use App\Models\FiberNode;
use App\Models\Odp;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\FiberColorService;
use App\Services\Network\FiberTopologyService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class FiberTopologyMapLivewireTest extends TestCase
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

    /**
     * @return array{0: Tenant, 1: FiberCable}
     */
    private function cableWithCores(int $totalCores = 4, int $tubeCount = 1): array
    {
        $tenant = Tenant::factory()->create();
        $from = FiberNode::factory()->create([
            'tenant_id' => $tenant->id, 'node_type' => 'otb',
            'latitude' => -6.20, 'longitude' => 106.80,
        ]);
        $to = FiberNode::factory()->create([
            'tenant_id' => $tenant->id, 'node_type' => 'odc',
            'latitude' => -6.25, 'longitude' => 106.85,
            'loss_in_db' => 1, 'loss_out_db' => 1,
        ]);

        $cable = app(FiberTopologyService::class)->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class, 'from_id' => $from->id,
            'to_type' => FiberNode::class, 'to_id' => $to->id,
            'total_cores' => $totalCores, 'tube_count' => $tubeCount, 'cores_per_tube' => (int) ($totalCores / $tubeCount),
        ]);

        return [$tenant, $cable];
    }

    public function test_fiber_cable_waypoints_table_migrated_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('fiber_cable_waypoints'));
        $this->assertEqualsCanonicalizing(
            ['id', 'fiber_cable_id', 'sequence', 'latitude', 'longitude', 'created_at', 'updated_at'],
            Schema::getColumnListing('fiber_cable_waypoints'),
        );
    }

    public function test_waypoints_relation_returns_them_in_sequence_order(): void
    {
        [, $cable] = $this->cableWithCores();

        FiberCableWaypoint::factory()->for($cable)->create(['sequence' => 3, 'latitude' => -6.3, 'longitude' => 106.9]);
        FiberCableWaypoint::factory()->for($cable)->create(['sequence' => 1, 'latitude' => -6.1, 'longitude' => 106.7]);
        FiberCableWaypoint::factory()->for($cable)->create(['sequence' => 2, 'latitude' => -6.2, 'longitude' => 106.8]);

        $this->assertSame([1, 2, 3], $cable->waypoints()->pluck('sequence')->all());
    }

    public function test_page_renders_with_no_default_cable_lines(): void
    {
        [$tenant] = $this->cableWithCores();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberTopologyMap::class)
            ->assertOk()
            ->assertSet('selectedCableIds', [])
            ->assertViewHas('lines', [])
            ->assertViewHas('markers', fn ($m) => count($m) === 2);
    }

    public function test_default_layers_are_fiber_nodes_only_cable_and_customer_off(): void
    {
        [$tenant] = $this->cableWithCores();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberTopologyMap::class)
            ->assertViewHas('defaultLayers', ['otb', 'closure', 'odc', 'odp'])
            ->assertViewHas('categories', ['cable', 'otb', 'closure', 'odc', 'odp', 'customer'])
            // all 6 checklist labels present on the page
            ->assertSee('Pelanggan')
            ->assertSee('Closure');
    }

    public function test_customer_layer_only_carries_customers_with_coordinates(): void
    {
        [$tenant] = $this->cableWithCores();
        Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Pak Har', 'address' => 'Blok C2', 'latitude' => -6.19, 'longitude' => 106.79]);
        Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Bu Nina', 'latitude' => null, 'longitude' => null]);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberTopologyMap::class)
            ->assertViewHas('customers', fn ($c) => count($c) === 1 && $c[0]['name'] === 'Pak Har' && $c[0]['address'] === 'Blok C2');
    }

    public function test_export_checklist_defaults_to_every_category_and_filters_the_kmz(): void
    {
        [$tenant] = $this->cableWithCores();
        Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Cust Map', 'latitude' => -6.19, 'longitude' => 106.79]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(FiberTopologyMap::class)
            ->assertSet('exportCategories', ['cable', 'otb', 'closure', 'odc', 'odp', 'customer']);

        // uncheck everything except OTB
        $component->set('exportCategories', ['otb'])
            ->call('exportKmz')
            ->assertOk();

        // verify the service produces the filtered KML for that same set
        $kml = app(FiberTopologyService::class)->buildTopologyKml(['otb']);
        $this->assertStringContainsString('<Point>', $kml);
        $this->assertStringNotContainsString('Pelanggan: Cust Map', $kml);
        $this->assertStringNotContainsString('<LineString>', $kml);

        $withCustomer = app(FiberTopologyService::class)->buildTopologyKml(['customer']);
        $this->assertStringContainsString('Pelanggan: Cust Map', $withCustomer);
    }

    public function test_selecting_a_cable_draws_one_neutral_coloured_line_not_a_core_colour(): void
    {
        [$tenant, $cable] = $this->cableWithCores();

        // the cable's cores carry real cycle colours (Biru/Orange/...) —
        // the map line must NOT pick any of them up.
        $coreHexes = $cable->cores->pluck('core_color')
            ->map(fn ($n) => app(FiberColorService::class)->hexForName($n))
            ->filter()->unique();
        $this->assertGreaterThan(1, $coreHexes->count());

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberTopologyMap::class)
            ->call('showCable', $cable->id)
            ->assertSet('selectedCableIds', [$cable->id])
            ->assertDispatched('topology-lines-updated')
            ->assertViewHas('lines', fn ($lines) => count($lines) === 1
                && $lines[0]['cable_id'] === $cable->id
                && $lines[0]['color'] === FiberTopologyService::CABLE_LINE_COLOR
                && ! $coreHexes->contains($lines[0]['color'])
                && $lines[0]['waypoints'] === []
                && count($lines[0]['endpoints']) === 2);
    }

    public function test_hiding_a_cable_removes_its_line(): void
    {
        [$tenant, $cable] = $this->cableWithCores();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberTopologyMap::class)
            ->call('showCable', $cable->id)
            ->call('hideCable', $cable->id)
            ->assertSet('selectedCableIds', [])
            ->assertViewHas('lines', []);
    }

    public function test_cable_query_param_preselects_the_line(): void
    {
        [$tenant, $cable] = $this->cableWithCores();

        Livewire::actingAs($this->admin($tenant))
            ->withQueryParams(['cable' => $cable->id])
            ->test(FiberTopologyMap::class)
            ->assertSet('selectedCableIds', [$cable->id])
            ->assertViewHas('lines', fn ($lines) => count($lines) === 1);
    }

    public function test_cable_options_are_per_cable_and_exclude_endpoints_without_coordinates(): void
    {
        [$tenant, $cable] = $this->cableWithCores();

        // a second cable with a coordinate-less endpoint — must be excluded
        $lone = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'otb', 'latitude' => -6.4, 'longitude' => 106.4]);
        $blind = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'closure', 'latitude' => null, 'longitude' => null]);
        app(FiberTopologyService::class)->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class, 'from_id' => $lone->id,
            'to_type' => FiberNode::class, 'to_id' => $blind->id,
            'total_cores' => 2, 'tube_count' => 1, 'cores_per_tube' => 2,
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberTopologyMap::class)
            ->assertViewHas('cableOptions', fn ($opts) => count($opts) === 1
                && $opts[0]['cable_id'] === $cable->id
                && $opts[0]['label'] === app(FiberTopologyService::class)->describeCable($cable->fresh())
                && str_starts_with($opts[0]['label'], 'Kabel 4 Core ')
                && ! str_contains($opts[0]['label'], '#'));
    }

    public function test_describe_cable_uses_endpoint_labels_not_the_numeric_id(): void
    {
        $tenant = Tenant::factory()->create();
        $otb = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'otb', 'local_label' => 'OTB48-Kaliwungu']);
        $closure = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'closure', 'local_label' => 'Closure-48']);
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'code' => 'ODP-KLW-01', 'name' => 'Depan Masjid']);
        $svc = app(FiberTopologyService::class);

        // fiber_node ↔ fiber_node
        $c1 = $svc->createCable(['tenant_id' => $tenant->id, 'from_type' => FiberNode::class, 'from_id' => $otb->id, 'to_type' => FiberNode::class, 'to_id' => $closure->id, 'total_cores' => 48, 'tube_count' => 4, 'cores_per_tube' => 12]);
        $this->assertSame('Kabel 48 Core OTB48-Kaliwungu ↔ Closure-48', $svc->describeCable($c1));

        // fiber_node ↔ odp (odp shows its code only, not "code - name")
        $c2 = $svc->createCable(['tenant_id' => $tenant->id, 'from_type' => FiberNode::class, 'from_id' => $closure->id, 'to_type' => Odp::class, 'to_id' => $odp->id, 'total_cores' => 2, 'tube_count' => 1, 'cores_per_tube' => 2]);
        $this->assertSame('Kabel 2 Core Closure-48 ↔ ODP-KLW-01', $svc->describeCable($c2));

        // soft-deleted endpoint → neutral fallback, no crash
        $closure->delete();
        $this->assertSame('Kabel 48 Core OTB48-Kaliwungu ↔ Titik dihapus', $svc->describeCable($c1->fresh()));
    }

    public function test_cable_checklist_is_wrapped_in_a_fixed_height_scroll_container(): void
    {
        [$tenant] = $this->cableWithCores();

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(FiberTopologyMap::class)
            ->html();

        // the list scrolls inside a bounded box, not the whole page
        $this->assertStringContainsString('max-h-24 overflow-y-auto', $html);

        // the "Pilih Semua"/"Kosongkan" toggle stays OUTSIDE (above) that box
        $togglePos = strpos($html, 'wire:click="toggleAllCables"');
        $scrollBoxPos = strpos($html, 'max-h-24 overflow-y-auto');
        $this->assertNotFalse($togglePos);
        $this->assertNotFalse($scrollBoxPos);
        $this->assertLessThan($scrollBoxPos, $togglePos, 'toggle must render before (outside) the scroll container');

        // the individual per-cable checkboxes live inside the scroll box
        $checkboxPos = strpos($html, 'wire:model.live="selectedCableIds"');
        $this->assertNotFalse($checkboxPos);
        $this->assertGreaterThan($scrollBoxPos, $checkboxPos);
    }

    /**
     * @return array{0: Tenant, 1: FiberCable, 2: FiberCable}
     */
    private function twoCables(): array
    {
        $tenant = Tenant::factory()->create();
        $a1 = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'otb', 'latitude' => -6.20, 'longitude' => 106.80]);
        $a2 = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'odc', 'latitude' => -6.25, 'longitude' => 106.85, 'loss_in_db' => 1, 'loss_out_db' => 1]);
        $b1 = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'otb', 'latitude' => -6.30, 'longitude' => 106.90]);
        $b2 = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'closure', 'latitude' => -6.35, 'longitude' => 106.95]);
        $svc = app(FiberTopologyService::class);
        $ca = $svc->createCable(['tenant_id' => $tenant->id, 'from_type' => FiberNode::class, 'from_id' => $a1->id, 'to_type' => FiberNode::class, 'to_id' => $a2->id, 'total_cores' => 2, 'tube_count' => 1, 'cores_per_tube' => 2]);
        $cb = $svc->createCable(['tenant_id' => $tenant->id, 'from_type' => FiberNode::class, 'from_id' => $b1->id, 'to_type' => FiberNode::class, 'to_id' => $b2->id, 'total_cores' => 2, 'tube_count' => 1, 'cores_per_tube' => 2]);

        return [$tenant, $ca, $cb];
    }

    public function test_multi_select_draws_several_neutral_cable_lines_at_once(): void
    {
        [$tenant, $ca, $cb] = $this->twoCables();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberTopologyMap::class)
            ->set('selectedCableIds', [$ca->id, $cb->id])
            ->assertDispatched('topology-lines-updated')
            ->assertViewHas('lines', fn ($lines) => count($lines) === 2
                && collect($lines)->pluck('cable_id')->sort()->values()->all() === collect([$ca->id, $cb->id])->sort()->values()->all()
                && collect($lines)->every(fn ($l) => $l['color'] === FiberTopologyService::CABLE_LINE_COLOR));
    }

    public function test_toggle_all_cables_selects_every_mappable_cable_then_clears(): void
    {
        [$tenant, $ca, $cb] = $this->twoCables();

        $c = Livewire::actingAs($this->admin($tenant))->test(FiberTopologyMap::class);

        $c->call('toggleAllCables')
            ->assertSet('selectedCableIds', fn ($ids) => collect($ids)->sort()->values()->all() === collect([$ca->id, $cb->id])->sort()->values()->all())
            ->assertViewHas('allCablesSelected', true)
            ->assertViewHas('lines', fn ($lines) => count($lines) === 2);

        $c->call('toggleAllCables')
            ->assertSet('selectedCableIds', [])
            ->assertViewHas('lines', []);
    }

    public function test_unchecking_one_cable_hides_only_that_cable(): void
    {
        [$tenant, $ca, $cb] = $this->twoCables();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberTopologyMap::class)
            ->set('selectedCableIds', [$ca->id, $cb->id])
            ->assertViewHas('lines', fn ($lines) => count($lines) === 2)
            // uncheck cable B
            ->set('selectedCableIds', [$ca->id])
            ->assertViewHas('lines', fn ($lines) => count($lines) === 1 && $lines[0]['cable_id'] === $ca->id);
    }

    public function test_save_route_replaces_all_existing_waypoints_not_stacks(): void
    {
        [$tenant, $cable] = $this->cableWithCores();

        $first = [
            ['lat' => -6.21, 'lng' => 106.81],
            ['lat' => -6.22, 'lng' => 106.82],
            ['lat' => -6.23, 'lng' => 106.83],
        ];
        $second = [
            ['lat' => -6.24, 'lng' => 106.84],
        ];

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberTopologyMap::class)
            ->call('saveRoute', $cable->id, $first)
            ->call('saveRoute', $cable->id, $second)
            ->assertDispatched('topology-lines-updated');

        $rows = $cable->waypoints()->get();
        $this->assertCount(1, $rows);
        $this->assertSame([1], $rows->pluck('sequence')->all());
        $this->assertEqualsWithDelta(-6.24, (float) $rows[0]->latitude, 0.0001);
        $this->assertSame(1, FiberCableWaypoint::count());
    }

    public function test_save_route_with_an_empty_list_clears_the_route(): void
    {
        [$tenant, $cable] = $this->cableWithCores();
        FiberCableWaypoint::factory()->for($cable)->create(['sequence' => 1]);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberTopologyMap::class)
            ->call('saveRoute', $cable->id, []);

        $this->assertSame(0, $cable->waypoints()->count());
    }

    public function test_selected_cable_line_includes_saved_waypoints_in_order(): void
    {
        [$tenant, $cable] = $this->cableWithCores();

        app(FiberTopologyService::class)->replaceCableWaypoints($cable, [
            ['lat' => -6.21, 'lng' => 106.81],
            ['lat' => -6.22, 'lng' => 106.82],
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberTopologyMap::class)
            ->call('showCable', $cable->id)
            ->assertViewHas('lines', fn ($lines) => count($lines[0]['waypoints']) === 2
                && abs($lines[0]['waypoints'][0][0] - (-6.21)) < 0.0001
                && abs($lines[0]['waypoints'][1][0] - (-6.22)) < 0.0001);
    }

    public function test_a_viewer_without_manage_cannot_save_a_route(): void
    {
        [$tenant, $cable] = $this->cableWithCores();
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo('network_infrastructure.view');

        Livewire::actingAs($viewer)
            ->test(FiberTopologyMap::class)
            ->call('saveRoute', $cable->id, [['lat' => -6.21, 'lng' => 106.81]])
            ->assertForbidden();

        $this->assertSame(0, $cable->waypoints()->count());
    }

    public function test_deleting_a_cable_cascades_its_waypoints(): void
    {
        [, $cable] = $this->cableWithCores();
        FiberCableWaypoint::factory()->for($cable)->create(['sequence' => 1]);

        $cable->delete();

        $this->assertSame(0, FiberCableWaypoint::count());
    }

    public function test_export_kmz_downloads_a_valid_zip_with_a_doc_kml(): void
    {
        [$tenant, $cable] = $this->cableWithCores();
        app(FiberTopologyService::class)->replaceCableWaypoints($cable, [['lat' => -6.22, 'lng' => 106.82]]);

        $response = Livewire::actingAs($this->admin($tenant))
            ->test(FiberTopologyMap::class)
            ->call('exportKmz')
            ->assertOk()
            ->assertFileDownloaded();

        // Livewire test returns the streamed download response; pull bytes
        $bytes = app(FiberTopologyService::class)->buildTopologyKmz();

        $path = tempnam(sys_get_temp_dir(), 'kmztest');
        file_put_contents($path, $bytes);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'kmz must be a valid zip');
        $kml = $zip->getFromName('doc.kml');
        $zip->close();
        @unlink($path);

        $this->assertNotFalse($kml);
        $this->assertStringContainsString('<kml', $kml);
        $this->assertStringContainsString('<Placemark>', $kml);
        $this->assertStringContainsString('<Point>', $kml);
        $this->assertStringContainsString('<LineString>', $kml);
        // the line runs from -> waypoint -> to (3 coordinate tuples)
        $this->assertStringContainsString('106.82,-6.22,0', $kml);
    }

    public function test_kml_omits_a_cable_with_an_endpoint_missing_coordinates(): void
    {
        [$tenant] = $this->cableWithCores();
        $a = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'otb', 'latitude' => -6.4, 'longitude' => 106.4]);
        $b = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'closure', 'latitude' => null, 'longitude' => null]);
        app(FiberTopologyService::class)->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class, 'from_id' => $a->id,
            'to_type' => FiberNode::class, 'to_id' => $b->id,
            'total_cores' => 2, 'tube_count' => 1, 'cores_per_tube' => 2,
        ]);

        $kml = app(FiberTopologyService::class)->buildTopologyKml();

        // exactly one LineString (the fully-coordinated cable), plus the
        // one coordinate-less node never becomes a Point placemark
        $this->assertSame(1, substr_count($kml, '<LineString>'));
    }

    public function test_satellite_base_layer_url_is_present_in_the_built_bundle(): void
    {
        $built = collect(glob(public_path('build/assets/app-*.js')))
            ->map(fn ($f) => (string) file_get_contents($f))
            ->implode("\n");

        $this->assertStringContainsString('server.arcgisonline.com', $built);
        $this->assertStringContainsString('World_Imagery', $built);
    }
}
