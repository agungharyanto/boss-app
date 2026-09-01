<?php

namespace Tests\Unit\Services\Installation;

use App\Models\Customer;
use App\Models\Odp;
use App\Models\OdpPort;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Installation\OdpLocatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OdpLocatorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_nearest_odp_port_that_is_available(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'latitude' => -6.2000,
            'longitude' => 106.8000,
        ]);

        $far = Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'latitude' => -6.9000, 'longitude' => 107.6000]);
        $near = Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'latitude' => -6.2001, 'longitude' => 106.8001]);

        OdpPort::factory()->forOdp($far)->create();
        $nearPort = OdpPort::factory()->forOdp($near)->create();

        $result = (new OdpLocatorService)->findNearestAvailable($customer);

        $this->assertSame($nearPort->id, $result->id);
    }

    public function test_skips_reserved_used_and_damaged_ports(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'latitude' => -6.2000,
            'longitude' => 106.8000,
        ]);

        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'latitude' => -6.2001, 'longitude' => 106.8001]);

        OdpPort::factory()->forOdp($odp)->reserved()->create();
        OdpPort::factory()->forOdp($odp)->used()->create();
        OdpPort::factory()->forOdp($odp)->damaged()->create();
        $available = OdpPort::factory()->forOdp($odp)->create();

        $result = (new OdpLocatorService)->findNearestAvailable($customer);

        $this->assertSame($available->id, $result->id);
    }

    public function test_returns_null_when_no_available_port_exists(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'latitude' => -6.2000,
            'longitude' => 106.8000,
        ]);

        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        OdpPort::factory()->forOdp($odp)->reserved()->create();

        $result = (new OdpLocatorService)->findNearestAvailable($customer);

        $this->assertNull($result);
    }

    public function test_returns_null_when_customer_has_no_coordinates(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);

        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        OdpPort::factory()->forOdp($odp)->create();

        $result = (new OdpLocatorService)->findNearestAvailable($customer);

        $this->assertNull($result);
    }

    public function test_skips_odps_belonging_to_a_different_reseller(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => $resellerA->id,
            'latitude' => -6.2000,
            'longitude' => 106.8000,
        ]);

        $odpB = Odp::factory()->forReseller($resellerB)->create(['latitude' => -6.2001, 'longitude' => 106.8001]);
        OdpPort::factory()->forOdp($odpB)->create();

        $odpA = Odp::factory()->forReseller($resellerA)->create(['latitude' => -6.3000, 'longitude' => 106.9000]);
        $portA = OdpPort::factory()->forOdp($odpA)->create();

        $result = (new OdpLocatorService)->findNearestAvailable($customer);

        $this->assertSame($portA->id, $result->id);
    }

    public function test_skips_reseller_odps_when_customer_is_direct(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'latitude' => -6.2000,
            'longitude' => 106.8000,
        ]);

        $resellerOdp = Odp::factory()->forReseller($reseller)->create(['latitude' => -6.2001, 'longitude' => 106.8001]);
        OdpPort::factory()->forOdp($resellerOdp)->create();

        $directOdp = Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'latitude' => -6.3000, 'longitude' => 106.9000]);
        $directPort = OdpPort::factory()->forOdp($directOdp)->create();

        $result = (new OdpLocatorService)->findNearestAvailable($customer);

        $this->assertSame($directPort->id, $result->id);
    }

    public function test_nearest_candidates_returns_several_ordered_by_distance_with_capacity(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id]));

        $near = Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'code' => 'ODP-NEAR', 'latitude' => -6.2001, 'longitude' => 106.8001, 'total_ports' => 8]);
        $mid = Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'code' => 'ODP-MID', 'latitude' => -6.2100, 'longitude' => 106.8100, 'total_ports' => 8]);
        $far = Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'code' => 'ODP-FAR', 'latitude' => -6.9000, 'longitude' => 107.6000, 'total_ports' => 8]);

        // near ODP is FULL — must still be listed (not filtered out)
        OdpPort::factory()->forOdp($near)->used()->count(8)->create();
        OdpPort::factory()->forOdp($mid)->used()->count(2)->create();

        $candidates = (new OdpLocatorService)->nearestCandidates(-6.2000, 106.8000, 5);

        $this->assertSame(['ODP-NEAR', 'ODP-MID', 'ODP-FAR'], array_column($candidates, 'code'));
        $this->assertSame(8, $candidates[0]['used_ports']);
        $this->assertSame(8, $candidates[0]['total_ports']);
        $this->assertSame(2, $candidates[1]['used_ports']);
        $this->assertLessThan($candidates[1]['distance_km'], $candidates[0]['distance_km']);
    }

    public function test_nearest_candidates_respects_the_limit(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id]));

        Odp::factory()->count(6)->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'latitude' => -6.20, 'longitude' => 106.80]);

        $this->assertCount(3, (new OdpLocatorService)->nearestCandidates(-6.20, 106.80, 3));
    }
}
