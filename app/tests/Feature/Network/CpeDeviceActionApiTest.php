<?php

namespace Tests\Feature\Network;

use App\Models\CpeActionLog;
use App\Models\CpeDevice;
use App\Models\CpeParameterMap;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CpeDeviceActionApiTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGenieAcsEnqueue(): void
    {
        Http::fake([
            'genieacs-nbi:7557/devices/*/tasks*' => Http::response(['_id' => 'genieacs-task-api-1'], 202),
            'genieacs-nbi:7557/devices*' => Http::response([[
                '_id' => 'F86CE1-F663NV3a-ZICG296C2E7B',
                '_deviceId' => ['_OUI' => 'F86CE1', '_ProductClass' => 'F663NV3a', '_SerialNumber' => 'ZICG296C2E7B'],
            ]], 200),
        ]);
    }

    private function resellerOwner(Tenant $tenant, Reseller $reseller): User
    {
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $reseller->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        return $owner;
    }

    private function resellerStaff(Tenant $tenant, Reseller $reseller): User
    {
        $staff = User::factory()->create(['tenant_id' => $tenant->id]);
        $reseller->users()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);

        return $staff;
    }

    public function test_reseller_owner_can_reboot_their_own_device(): void
    {
        $this->fakeGenieAcsEnqueue();
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->forReseller($reseller)->create(['genieacs_device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B']);
        $owner = $this->resellerOwner($tenant, $reseller);

        $response = $this->actingAs($owner)->postJson("/api/v1/cpe-devices/{$device->id}/actions/reboot");

        $response->assertOk();
        $this->assertSame('delivered', $response->json('data.status'));
        $this->assertStringContainsString('terkirim', $response->json('message'));
        $this->assertStringNotContainsString('berhasil reboot', strtolower($response->json('message')));
    }

    public function test_reseller_staff_can_also_reboot_not_just_owner(): void
    {
        $this->fakeGenieAcsEnqueue();
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->forReseller($reseller)->create(['genieacs_device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B']);
        $staff = $this->resellerStaff($tenant, $reseller);

        $this->actingAs($staff)
            ->postJson("/api/v1/cpe-devices/{$device->id}/actions/reboot")
            ->assertOk();
    }

    /**
     * 404, not 403 — same behavior as CpeDeviceResellerIsolationTest's own
     * show() test: BelongsToResellerScope already excludes deviceB from the
     * route-model-binding query while acting as resellerA, before the
     * `manage` policy check ever runs.
     */
    public function test_reseller_cannot_reboot_another_resellers_device(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $deviceB = CpeDevice::factory()->forReseller($resellerB)->create();
        $ownerA = $this->resellerOwner($tenant, $resellerA);

        $this->actingAs($ownerA)
            ->postJson("/api/v1/cpe-devices/{$deviceB->id}/actions/reboot")
            ->assertNotFound();
    }

    public function test_user_with_no_reseller_membership_and_no_permission_cannot_reboot(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->postJson("/api/v1/cpe-devices/{$device->id}/actions/reboot")
            ->assertForbidden();
    }

    public function test_admin_with_manage_permission_can_reboot_a_direct_device(): void
    {
        $this->fakeGenieAcsEnqueue();
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'genieacs_device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B']);
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->givePermissionTo(Permission::firstOrCreate(['name' => 'cpe_devices.manage', 'guard_name' => 'web']));

        $this->actingAs($admin)
            ->postJson("/api/v1/cpe-devices/{$device->id}/actions/reboot")
            ->assertOk();
    }

    public function test_wifi_endpoint_requires_at_least_one_of_ssid_or_password(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->forReseller($reseller)->create();
        $owner = $this->resellerOwner($tenant, $reseller);

        $this->actingAs($owner)
            ->postJson("/api/v1/cpe-devices/{$device->id}/actions/wifi", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ssid', 'password']);
    }

    public function test_wifi_endpoint_accepts_ssid_only_and_delivers(): void
    {
        $this->fakeGenieAcsEnqueue();
        CpeParameterMap::factory()->create([
            'oui' => 'F86CE1',
            'product_class' => 'F663NV3a',
            'parameter_key' => 'wifi_ssid',
            'parameter_path' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
        ]);
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->forReseller($reseller)->create(['genieacs_device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B']);
        $owner = $this->resellerOwner($tenant, $reseller);

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/cpe-devices/{$device->id}/actions/wifi", ['ssid' => 'RumahBaru']);

        $response->assertOk();
        $this->assertSame('delivered', $response->json('data.status'));
        $this->assertSame('set_ssid', $response->json('data.action_type'));
    }

    public function test_actions_history_endpoint_returns_this_devices_logs_only(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->forReseller($reseller)->create();
        $otherDevice = CpeDevice::factory()->forReseller($reseller)->create();
        $owner = $this->resellerOwner($tenant, $reseller);

        CpeActionLog::factory()->for($device, 'cpeDevice')->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);
        CpeActionLog::factory()->for($otherDevice, 'cpeDevice')->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);

        $response = $this->actingAs($owner)->getJson("/api/v1/cpe-devices/{$device->id}/actions");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }
}
