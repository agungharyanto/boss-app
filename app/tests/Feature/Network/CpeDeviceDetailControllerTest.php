<?php

namespace Tests\Feature\Network;

use App\Enums\CpeParameterConversionFormula;
use App\Models\CpeActionLog;
use App\Models\CpeConnectedHost;
use App\Models\CpeDevice;
use App\Models\CpeParameterMap;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /api/internal/cpe-devices/{id}/detail (v0.7.6-follow-up) — the plain
 * HTML fragment injected as a DataTables child row when the "+" cell is
 * clicked. Covers the data it shows AND the manage-action gating that used
 * to live in the old Detail modal's Livewire test.
 */
class CpeDeviceDetailControllerTest extends TestCase
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
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_view_only_admin_sees_data_but_not_manage_actions(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'serial_number' => 'SNVIEWONLY']);
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo(Permission::firstOrCreate(['name' => 'cpe_devices.view', 'guard_name' => 'web']));

        $response = $this->actingAs($viewer)
            ->get("/api/internal/cpe-devices/{$device->id}/detail")
            ->assertOk();

        $response->assertSee('SNVIEWONLY');
        $response->assertSee('Riwayat Aksi');
        $response->assertDontSee('Reboot', false);
        $response->assertDontSee('Ganti WiFi');
        $response->assertDontSee('cpeRemove(', false);
        $response->assertDontSee('Ganti Modem');
    }

    public function test_a_user_with_neither_permission_nor_reseller_membership_cannot_view_detail(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->get("/api/internal/cpe-devices/{$device->id}/detail")
            ->assertForbidden();
    }

    public function test_manage_admin_sees_reboot_ganti_wifi_ganti_modem_and_remove(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->admin($tenant))
            ->get("/api/internal/cpe-devices/{$device->id}/detail")
            ->assertOk();

        $response->assertSee('cpeReboot('.$device->id.')', false);
        $response->assertSee('cpeRemove('.$device->id.',', false);
        $response->assertSee('cpeReplaceModem('.$device->id.',', false);
        $response->assertSee('Ganti WiFi');
        $response->assertSee('Ganti Modem');
    }

    public function test_detail_shows_resolved_mac_rx_tx_and_uptime(): void
    {
        Http::fake([
            'genieacs-nbi:7557/devices*' => Http::response([[
                '_id' => 'F86CE1-F663NV3a-ZICG296C2E7B',
                '_deviceId' => ['_OUI' => 'F86CE1', '_ProductClass' => 'F663NV3a', '_SerialNumber' => 'ZICG296C2E7B'],
                'InternetGatewayDevice' => [
                    'LANDevice' => ['1' => ['MACAddress' => ['_value' => 'AA:BB:CC:DD:EE:FF']]],
                    'WANDevice' => ['1' => ['X_CT-COM_GponInterfaceConfig' => [
                        'RXPower' => ['_value' => -20.5],
                        'TXPower' => ['_value' => 2.1],
                    ]]],
                    'DeviceInfo' => ['UpTime' => ['_value' => 6567]],
                ],
            ]], 200),
        ]);
        CpeParameterMap::factory()->create([
            'oui' => 'F86CE1', 'product_class' => 'F663NV3a', 'parameter_key' => 'mac_address',
            'parameter_path' => 'InternetGatewayDevice.LANDevice.1.MACAddress',
            'conversion_formula' => CpeParameterConversionFormula::Raw,
        ]);
        CpeParameterMap::factory()->create([
            'oui' => 'F86CE1', 'product_class' => 'F663NV3a', 'parameter_key' => 'rx_power_dbm',
            'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower',
            'conversion_formula' => CpeParameterConversionFormula::Raw,
        ]);
        CpeParameterMap::factory()->create([
            'oui' => 'F86CE1', 'product_class' => 'F663NV3a', 'parameter_key' => 'tx_power_dbm',
            'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.TXPower',
            'conversion_formula' => CpeParameterConversionFormula::Raw,
        ]);
        CpeParameterMap::factory()->create([
            'oui' => 'F86CE1', 'product_class' => 'F663NV3a', 'parameter_key' => 'device_uptime_seconds',
            'parameter_path' => 'InternetGatewayDevice.DeviceInfo.UpTime',
            'conversion_formula' => CpeParameterConversionFormula::Raw,
        ]);
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'genieacs_device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B']);

        $this->actingAs($this->admin($tenant))
            ->get("/api/internal/cpe-devices/{$device->id}/detail")
            ->assertOk()
            ->assertSee('AA:BB:CC:DD:EE:FF')
            ->assertSee('-20.50 dBm')
            ->assertSee('2.10 dBm')
            ->assertSee('1j 49m');
    }

    public function test_detail_shows_only_this_devices_history_logs(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);
        $otherDevice = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        CpeActionLog::factory()->for($device, 'cpeDevice')->create(['tenant_id' => $tenant->id, 'action_type' => 'reboot']);
        CpeActionLog::factory()->for($otherDevice, 'cpeDevice')->create([
            'tenant_id' => $tenant->id, 'action_type' => 'reboot', 'genieacs_task_id' => 'should-not-appear',
        ]);

        $this->actingAs($this->admin($tenant))
            ->get("/api/internal/cpe-devices/{$device->id}/detail")
            ->assertOk()
            ->assertSee('Terkirim ke GenieACS')
            ->assertDontSee('should-not-appear');
    }

    public function test_detail_shows_only_this_devices_connected_hosts(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);
        $otherDevice = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        CpeConnectedHost::factory()->create(['cpe_device_id' => $device->id, 'tenant_id' => $tenant->id, 'hostname' => 'MyPhoneVisible']);
        CpeConnectedHost::factory()->create(['cpe_device_id' => $otherDevice->id, 'tenant_id' => $tenant->id, 'hostname' => 'ShouldNotAppear']);

        $this->actingAs($this->admin($tenant))
            ->get("/api/internal/cpe-devices/{$device->id}/detail")
            ->assertOk()
            ->assertSee('MyPhoneVisible')
            ->assertDontSee('ShouldNotAppear');
    }

    public function test_a_reseller_cannot_view_another_resellers_device_detail(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = \App\Models\Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = \App\Models\Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $deviceB = CpeDevice::factory()->forReseller($resellerB)->create();
        $ownerA = User::factory()->create(['tenant_id' => $tenant->id]);
        $resellerA->users()->attach($ownerA->id, ['role' => 'owner', 'status' => 'active']);

        $this->actingAs($ownerA)
            ->get("/api/internal/cpe-devices/{$deviceB->id}/detail")
            ->assertNotFound();
    }
}
