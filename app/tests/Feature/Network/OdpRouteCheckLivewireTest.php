<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\OdpRouteCheck;
use App\Models\Customer;
use App\Models\Odp;
use App\Models\OdpPort;
use App\Models\SalesRouteNote;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class OdpRouteCheckLivewireTest extends TestCase
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

    private function fakeOsrm(array $distances): void
    {
        $routes = array_map(fn ($d) => [
            'distance' => $d,
            'duration' => $d / 8,
            'geometry' => ['type' => 'LineString', 'coordinates' => [[106.80, -6.20], [106.81, -6.21]]],
        ], $distances);

        Http::fake(['*' => Http::response(['code' => 'Ok', 'routes' => $routes])]);
    }

    public function test_shows_nearest_odp_candidates_once_a_point_is_set(): void
    {
        $tenant = Tenant::factory()->create();
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'code' => 'ODP-A', 'latitude' => -6.2001, 'longitude' => 106.8001, 'total_ports' => 8]);
        OdpPort::factory()->forOdp($odp)->used()->count(7)->create(); // 88% -> "penuh" red

        Livewire::actingAs($this->admin($tenant))
            ->test(OdpRouteCheck::class)
            ->assertViewHas('candidates', fn ($c) => $c === [])
            ->set('latitude', '-6.2000')
            ->set('longitude', '106.8000')
            ->assertViewHas('candidates', fn ($c) => count($c) === 1
                && $c[0]['code'] === 'ODP-A'
                && $c[0]['used_ports'] === 7
                && $c[0]['zone_label'] === 'penuh')
            ->assertDispatched('candidates-updated');
    }

    public function test_calculate_routes_returns_every_alternative_sorted_and_dispatches_to_the_map(): void
    {
        $tenant = Tenant::factory()->create();
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'latitude' => -6.2001, 'longitude' => 106.8001, 'total_ports' => 8]);
        $this->fakeOsrm([2400, 1500, 3000]);

        Livewire::actingAs($this->admin($tenant))
            ->test(OdpRouteCheck::class)
            ->set('latitude', '-6.2000')
            ->set('longitude', '106.8000')
            ->set('targetOdpId', $odp->id)
            ->call('calculateRoutes')
            ->assertHasNoErrors()
            ->assertDispatched('routes-updated')
            ->assertSet('routeOptions', fn ($opts) => count($opts) === 3
                && $opts[0]['label'] === 'Rekomendasi'
                && $opts[0]['distance_meters'] === 1500.0
                && $opts[1]['label'] === 'Alternatif B'
                && $opts[2]['label'] === 'Alternatif C');
    }

    public function test_calculate_routes_rejects_an_odp_that_is_not_a_nearby_candidate(): void
    {
        $tenant = Tenant::factory()->create();
        Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'latitude' => -6.2001, 'longitude' => 106.8001, 'total_ports' => 8]);
        $farAway = Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'latitude' => 10.0, 'longitude' => 120.0, 'total_ports' => 8]);
        // 9 closer ODPs so $farAway falls outside the 8-candidate window
        Odp::factory()->count(9)->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'latitude' => -6.2002, 'longitude' => 106.8002, 'total_ports' => 8]);
        $this->fakeOsrm([1000]);

        Livewire::actingAs($this->admin($tenant))
            ->test(OdpRouteCheck::class)
            ->set('latitude', '-6.2000')
            ->set('longitude', '106.8000')
            ->set('targetOdpId', $farAway->id)
            ->call('calculateRoutes')
            ->assertHasErrors('targetOdpId')
            ->assertSet('routeOptions', []);
    }

    public function test_falls_back_to_a_straight_line_when_osrm_is_down_and_marks_it(): void
    {
        $tenant = Tenant::factory()->create();
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'latitude' => -6.2001, 'longitude' => 106.8001, 'total_ports' => 8]);
        Http::fake(fn (Request $r) => throw new ConnectionException('down'));

        Livewire::actingAs($this->admin($tenant))
            ->test(OdpRouteCheck::class)
            ->set('latitude', '-6.2000')
            ->set('longitude', '106.8000')
            ->set('targetOdpId', $odp->id)
            ->call('calculateRoutes')
            ->assertSet('routeOptions', fn ($opts) => count($opts) === 1
                && $opts[0]['is_fallback'] === true
                && str_contains($opts[0]['label'], 'routing tidak tersedia'));
    }

    public function test_saves_a_route_note_for_a_prospect_without_a_customer_record(): void
    {
        $tenant = Tenant::factory()->create();
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'latitude' => -6.2001, 'longitude' => 106.8001, 'total_ports' => 8]);
        $this->fakeOsrm([1800, 2500]);
        $admin = $this->admin($tenant);

        Livewire::actingAs($admin)
            ->test(OdpRouteCheck::class)
            ->set('latitude', '-6.2000')
            ->set('longitude', '106.8000')
            ->set('targetOdpId', $odp->id)
            ->set('prospectName', 'Pak Budi')
            ->set('prospectAddress', 'Jl. Melati 3')
            ->call('calculateRoutes')
            ->set('routeNotes.0', 'Via Jalan Raya')
            ->call('saveRoute', 0)
            ->assertHasNoErrors();

        $note = SalesRouteNote::first();
        $this->assertNotNull($note);
        $this->assertNull($note->customer_id);
        $this->assertSame('Pak Budi', $note->prospect_name);
        $this->assertSame('Jl. Melati 3', $note->prospect_address);
        $this->assertSame($odp->id, $note->target_odp_id);
        $this->assertSame('Rekomendasi', $note->route_label);
        $this->assertSame(1800, $note->distance_meters);
        $this->assertSame('Via Jalan Raya', $note->note);
        $this->assertSame($admin->id, $note->created_by);
        $this->assertFalse($note->is_straight_line_estimate);
        $this->assertSame('LineString', $note->route_geometry['type']);
    }

    public function test_saves_a_route_note_linked_to_an_existing_customer(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Wati', 'address' => 'Blok D', 'latitude' => -6.2000, 'longitude' => 106.8000]);
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'latitude' => -6.2001, 'longitude' => 106.8001, 'total_ports' => 8]);
        $this->fakeOsrm([1200]);

        Livewire::actingAs($this->admin($tenant))
            ->test(OdpRouteCheck::class)
            ->set('customerSearch', 'Wati')
            ->call('selectCustomer', $customer->id)
            ->assertSet('latitude', '-6.2000000')
            ->set('targetOdpId', $odp->id)
            ->call('calculateRoutes')
            ->call('saveRoute', 0)
            ->assertHasNoErrors();

        $note = SalesRouteNote::first();
        $this->assertSame($customer->id, $note->customer_id);
        $this->assertNull($note->prospect_name);
    }

    public function test_fallback_route_note_is_flagged_as_straight_line_estimate(): void
    {
        $tenant = Tenant::factory()->create();
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'latitude' => -6.2001, 'longitude' => 106.8001, 'total_ports' => 8]);
        Http::fake(['*' => Http::response('nope', 503)]);

        Livewire::actingAs($this->admin($tenant))
            ->test(OdpRouteCheck::class)
            ->set('latitude', '-6.2000')
            ->set('longitude', '106.8000')
            ->set('targetOdpId', $odp->id)
            ->set('prospectName', 'X')
            ->call('calculateRoutes')
            ->call('saveRoute', 0)
            ->assertHasNoErrors();

        $this->assertTrue(SalesRouteNote::first()->is_straight_line_estimate);
    }

    public function test_a_non_privileged_user_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('customer_service');

        Livewire::actingAs($user)
            ->test(OdpRouteCheck::class)
            ->assertForbidden();
    }
}
