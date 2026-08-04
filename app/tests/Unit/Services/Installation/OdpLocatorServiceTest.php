<?php

namespace Tests\Unit\Services\Installation;

use App\Models\Customer;
use App\Models\Odp;
use App\Models\OdpPort;
use App\Models\Reseller;
use App\Models\Tenant;
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
}
