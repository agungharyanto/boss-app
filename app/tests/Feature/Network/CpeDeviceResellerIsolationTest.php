<?php

namespace Tests\Feature\Network;

use App\Models\CpeDevice;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CpeDeviceResellerIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function resellerOwner(Tenant $tenant, Reseller $reseller): User
    {
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $reseller->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        return $owner;
    }

    public function test_api_index_only_returns_the_acting_resellers_own_devices(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        $deviceA = CpeDevice::factory()->forReseller($resellerA)->create();
        CpeDevice::factory()->forReseller($resellerB)->create();

        $ownerA = $this->resellerOwner($tenant, $resellerA);

        $response = $this->actingAs($ownerA)->getJson('/api/v1/cpe-devices');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($deviceA->id));
        $this->assertCount(1, $ids);
    }

    public function test_api_show_404s_for_another_resellers_device(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        $deviceB = CpeDevice::factory()->forReseller($resellerB)->create();

        $ownerA = $this->resellerOwner($tenant, $resellerA);

        $this->actingAs($ownerA)
            ->getJson("/api/v1/cpe-devices/{$deviceB->id}")
            ->assertNotFound();
    }

    public function test_isp_admin_sees_every_device_regardless_of_reseller(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->forReseller($reseller)->create();

        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->givePermissionTo(Permission::firstOrCreate(['name' => 'cpe_devices.view', 'guard_name' => 'web']));

        $this->actingAs($admin)
            ->getJson("/api/v1/cpe-devices/{$device->id}")
            ->assertOk();
    }
}
