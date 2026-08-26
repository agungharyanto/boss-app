<?php

namespace Tests\Feature\Network;

use App\Models\CpeActionLog;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The session-authenticated (routes/web.php) internal action endpoints
 * behind the /cpe-devices DataTables child row (v0.7.6-follow-up) —
 * reboot/wifi/replace-modem/destroy. These reuse the exact same Form
 * Requests + CpeActionService/CpeBindingService methods as the v1 API
 * (see CpeDeviceActionApiTest for that coverage) — this file exists to
 * prove the NEW route/controller wiring itself works, not to re-litigate
 * the business logic those other tests already cover.
 */
class CpeDeviceActionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(Tenant $tenant): User
    {
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');

        return $admin;
    }

    private function fakeGenieAcsEnqueue(): void
    {
        Http::fake([
            'genieacs-nbi:7557/devices/*/tasks*' => Http::response(['_id' => 'genieacs-task-child-row-1'], 202),
            'genieacs-nbi:7557/devices*' => Http::response([[
                '_id' => 'F86CE1-F663NV3a-ZICG296C2E7B',
                '_deviceId' => ['_OUI' => 'F86CE1', '_ProductClass' => 'F663NV3a', '_SerialNumber' => 'ZICG296C2E7B'],
            ]], 200),
        ]);
    }

    public function test_reboot_returns_the_envelope_and_creates_a_delivered_log(): void
    {
        $this->fakeGenieAcsEnqueue();
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'genieacs_device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B']);

        $response = $this->actingAs($this->admin($tenant))
            ->postJson("/api/internal/cpe-devices/{$device->id}/reboot")
            ->assertOk();

        $this->assertTrue($response->json('success'));
        $this->assertSame('delivered', $response->json('data.status'));
        $this->assertStringContainsString('terkirim', $response->json('message'));
        $this->assertDatabaseHas('cpe_action_logs', ['cpe_device_id' => $device->id, 'status' => 'delivered']);
    }

    public function test_reboot_without_genieacs_device_id_fails_honestly(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'genieacs_device_id' => null]);

        $response = $this->actingAs($this->admin($tenant))
            ->postJson("/api/internal/cpe-devices/{$device->id}/reboot")
            ->assertOk();

        $this->assertStringContainsString('GAGAL', $response->json('message'));
    }

    public function test_view_only_user_cannot_reboot(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo(Permission::firstOrCreate(['name' => 'cpe_devices.view', 'guard_name' => 'web']));

        $this->actingAs($viewer)
            ->postJson("/api/internal/cpe-devices/{$device->id}/reboot")
            ->assertForbidden();
    }

    public function test_wifi_requires_at_least_one_field(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($this->admin($tenant))
            ->postJson("/api/internal/cpe-devices/{$device->id}/wifi", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ssid', 'password']);
    }

    public function test_wifi_submits_and_logs_delivered(): void
    {
        $this->fakeGenieAcsEnqueue();
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'genieacs_device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B']);

        $response = $this->actingAs($this->admin($tenant))
            ->postJson("/api/internal/cpe-devices/{$device->id}/wifi", ['ssid' => 'RumahBaru'])
            ->assertOk();

        $this->assertStringContainsString('terkirim', $response->json('message'));
        $this->assertDatabaseHas('cpe_action_logs', ['cpe_device_id' => $device->id, 'status' => 'delivered']);
    }

    /**
     * 2026-08-17: per-SSID "Ganti WiFi" on the standalone detail page —
     * `ssid_index` in the request must land on the exact WLANConfiguration
     * instance targeted, defaulting to 1 when omitted (see
     * test_wifi_submits_and_logs_delivered above, which omits it).
     */
    public function test_wifi_with_ssid_index_targets_that_wlan_configuration_instance(): void
    {
        $this->fakeGenieAcsEnqueue();
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'genieacs_device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B']);

        $this->actingAs($this->admin($tenant))
            ->postJson("/api/internal/cpe-devices/{$device->id}/wifi", ['ssid' => 'TokenWifi', 'ssid_index' => 4])
            ->assertOk();

        $this->assertDatabaseHas('cpe_action_logs', [
            'cpe_device_id' => $device->id,
            'status' => 'delivered',
        ]);
        $log = CpeActionLog::where('cpe_device_id', $device->id)->firstOrFail();
        $this->assertSame(4, $log->parameters['ssid_index']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/tasks')) {
                return true;
            }

            return $request['parameterValues'] === [
                ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.4.SSID', 'TokenWifi', 'xsd:string'],
            ];
        });
    }

    public function test_wifi_rejects_ssid_index_out_of_range(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($this->admin($tenant))
            ->postJson("/api/internal/cpe-devices/{$device->id}/wifi", ['ssid' => 'x', 'ssid_index' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ssid_index']);
    }

    public function test_ssid_enabled_toggles_and_logs_delivered(): void
    {
        $this->fakeGenieAcsEnqueue();
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'genieacs_device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B']);

        $response = $this->actingAs($this->admin($tenant))
            ->postJson("/api/internal/cpe-devices/{$device->id}/ssid-enabled", ['ssid_index' => 4, 'enabled' => false])
            ->assertOk();

        $this->assertStringContainsString('terkirim', $response->json('message'));
        $this->assertDatabaseHas('cpe_action_logs', [
            'cpe_device_id' => $device->id,
            'action_type' => 'set_ssid_enabled',
            'status' => 'delivered',
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/tasks')) {
                return true;
            }

            return $request['parameterValues'] === [
                ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.4.Enable', false, 'xsd:boolean'],
            ];
        });
    }

    public function test_ssid_enabled_requires_ssid_index_and_enabled(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($this->admin($tenant))
            ->postJson("/api/internal/cpe-devices/{$device->id}/ssid-enabled", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ssid_index', 'enabled']);
    }

    public function test_view_only_user_cannot_toggle_ssid_enabled(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo(Permission::firstOrCreate(['name' => 'cpe_devices.view', 'guard_name' => 'web']));

        $this->actingAs($viewer)
            ->postJson("/api/internal/cpe-devices/{$device->id}/ssid-enabled", ['ssid_index' => 1, 'enabled' => true])
            ->assertForbidden();
    }

    public function test_sync_now_returns_the_envelope_and_creates_a_delivered_log_with_both_task_ids(): void
    {
        $this->fakeGenieAcsEnqueue();
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'genieacs_device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B']);

        $response = $this->actingAs($this->admin($tenant))
            ->postJson("/api/internal/cpe-devices/{$device->id}/sync-now")
            ->assertOk();

        $this->assertStringContainsString('terkirim', $response->json('message'));
        $this->assertDatabaseHas('cpe_action_logs', [
            'cpe_device_id' => $device->id,
            'action_type' => 'sync_now',
            'status' => 'delivered',
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/tasks')) {
                return true;
            }

            return $request['name'] === 'refreshObject';
        });
    }

    public function test_view_only_user_cannot_sync_now(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo(Permission::firstOrCreate(['name' => 'cpe_devices.view', 'guard_name' => 'web']));

        $this->actingAs($viewer)
            ->postJson("/api/internal/cpe-devices/{$device->id}/sync-now")
            ->assertForbidden();
    }

    public function test_destroy_unbinds_and_writes_a_rejection_row(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'genieacs_device_id' => 'F86CE1-F663NV3a-SNTOUNBIND',
        ]);

        $response = $this->actingAs($this->admin($tenant))
            ->deleteJson("/api/internal/cpe-devices/{$device->id}")
            ->assertOk();

        $this->assertStringContainsString('unbind', $response->json('message'));
        $this->assertDatabaseMissing('cpe_devices', ['id' => $device->id]);
        $this->assertDatabaseHas('cpe_binding_rejections', [
            'genieacs_device_id' => 'F86CE1-F663NV3a-SNTOUNBIND',
            'customer_id' => $customer->id,
        ]);
    }

    public function test_view_only_user_cannot_destroy(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo(Permission::firstOrCreate(['name' => 'cpe_devices.view', 'guard_name' => 'web']));

        $this->actingAs($viewer)
            ->deleteJson("/api/internal/cpe-devices/{$device->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('cpe_devices', ['id' => $device->id]);
    }

    public function test_replace_modem_unbinds_old_device_without_a_rejection_and_binds_the_new_serial(): void
    {
        Http::fake([
            'genieacs-nbi:7557/devices*' => Http::response([[
                '_id' => 'F86CE1-F663NV3a-SNNEWMODEM',
                '_deviceId' => ['_OUI' => 'F86CE1', '_ProductClass' => 'F663NV3a', '_SerialNumber' => 'SNNEWMODEM'],
            ]], 200),
        ]);
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $oldDevice = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'genieacs_device_id' => 'F86CE1-F663NV3a-SNOLDMODEM',
            'serial_number' => 'SNOLDMODEM',
        ]);

        $response = $this->actingAs($this->admin($tenant))
            ->postJson("/api/internal/cpe-devices/{$oldDevice->id}/replace-modem", ['serial_number' => 'SNNEWMODEM'])
            ->assertOk();

        $this->assertStringContainsString('diganti', $response->json('message'));
        $this->assertDatabaseMissing('cpe_devices', ['id' => $oldDevice->id]);
        $this->assertDatabaseMissing('cpe_binding_rejections', [
            'genieacs_device_id' => 'F86CE1-F663NV3a-SNOLDMODEM',
            'customer_id' => $customer->id,
        ]);
        $this->assertDatabaseHas('cpe_devices', [
            'serial_number' => 'SNNEWMODEM',
            'customer_id' => $customer->id,
            'genieacs_device_id' => 'F86CE1-F663NV3a-SNNEWMODEM',
        ]);
    }

    public function test_replace_modem_requires_a_serial_number(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($this->admin($tenant))
            ->postJson("/api/internal/cpe-devices/{$device->id}/replace-modem", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['serial_number']);
    }

    public function test_view_only_user_cannot_replace_modem(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo(Permission::firstOrCreate(['name' => 'cpe_devices.view', 'guard_name' => 'web']));

        $this->actingAs($viewer)
            ->postJson("/api/internal/cpe-devices/{$device->id}/replace-modem", ['serial_number' => 'SNSHOULDNOTWORK'])
            ->assertForbidden();
    }
}
