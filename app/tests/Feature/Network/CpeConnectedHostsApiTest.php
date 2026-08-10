<?php

namespace Tests\Feature\Network;

use App\Models\CpeConnectedHost;
use App\Models\CpeDevice;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CpeConnectedHostsApiTest extends TestCase
{
    use RefreshDatabase;

    private function resellerOwner(Tenant $tenant, Reseller $reseller): User
    {
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $reseller->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        return $owner;
    }

    public function test_reseller_owner_can_list_their_own_devices_connected_hosts(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->forReseller($reseller)->create();
        CpeConnectedHost::factory()->create(['cpe_device_id' => $device->id, 'tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);
        $owner = $this->resellerOwner($tenant, $reseller);

        $response = $this->actingAs($owner)->getJson("/api/v1/cpe-devices/{$device->id}/connected-hosts");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_active_only_filter_excludes_inactive_hosts(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->forReseller($reseller)->create();
        CpeConnectedHost::factory()->create(['cpe_device_id' => $device->id, 'tenant_id' => $tenant->id, 'reseller_id' => $reseller->id, 'is_active' => true, 'mac_address' => 'AA:AA:AA:AA:AA:AA']);
        CpeConnectedHost::factory()->create(['cpe_device_id' => $device->id, 'tenant_id' => $tenant->id, 'reseller_id' => $reseller->id, 'is_active' => false, 'mac_address' => 'BB:BB:BB:BB:BB:BB']);
        $owner = $this->resellerOwner($tenant, $reseller);

        $response = $this->actingAs($owner)->getJson("/api/v1/cpe-devices/{$device->id}/connected-hosts?active_only=true");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('AA:AA:AA:AA:AA:AA', $response->json('data.0.mac_address'));
    }

    public function test_without_active_only_both_active_and_inactive_hosts_are_returned(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->forReseller($reseller)->create();
        CpeConnectedHost::factory()->create(['cpe_device_id' => $device->id, 'tenant_id' => $tenant->id, 'reseller_id' => $reseller->id, 'is_active' => true]);
        CpeConnectedHost::factory()->create(['cpe_device_id' => $device->id, 'tenant_id' => $tenant->id, 'reseller_id' => $reseller->id, 'is_active' => false]);
        $owner = $this->resellerOwner($tenant, $reseller);

        $this->actingAs($owner)
            ->getJson("/api/v1/cpe-devices/{$device->id}/connected-hosts")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_reseller_cannot_see_another_resellers_device_connected_hosts(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $deviceB = CpeDevice::factory()->forReseller($resellerB)->create();
        $ownerA = $this->resellerOwner($tenant, $resellerA);

        $this->actingAs($ownerA)
            ->getJson("/api/v1/cpe-devices/{$deviceB->id}/connected-hosts")
            ->assertNotFound();
    }
}
