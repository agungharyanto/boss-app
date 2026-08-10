<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\CpeDeviceIndex;
use App\Models\CpeActionLog;
use App\Models\CpeDevice;
use App\Models\CpeParameterMap;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CpeDeviceIndexLivewireTest extends TestCase
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

    public function test_non_admin_non_reseller_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($user)
            ->test(CpeDeviceIndex::class)
            ->assertForbidden();
    }

    public function test_admin_can_render_and_list_existing_devices(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'serial_number' => 'SNVISIBLE001']);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeDeviceIndex::class)
            ->assertOk()
            ->assertSee('SNVISIBLE001');
    }

    public function test_search_filters_by_serial_number(): void
    {
        $tenant = Tenant::factory()->create();
        CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'serial_number' => 'SNMATCHME']);
        CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'serial_number' => 'SNOTHER999']);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeDeviceIndex::class)
            ->set('search', 'MATCHME')
            ->assertSee('SNMATCHME')
            ->assertDontSee('SNOTHER999');
    }

    /**
     * View-only (cpe_devices.view, no .manage) must never see the Reboot/
     * Ganti WiFi buttons — same "an admin can always SEE, but manage() is
     * separate" posture as WhatsappSessionPolicy in this codebase.
     */
    public function test_view_only_admin_does_not_see_reboot_or_wifi_buttons(): void
    {
        $tenant = Tenant::factory()->create();
        CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'serial_number' => 'SNVIEWONLY']);
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo(Permission::firstOrCreate(['name' => 'cpe_devices.view', 'guard_name' => 'web']));

        Livewire::actingAs($viewer)
            ->test(CpeDeviceIndex::class)
            ->assertSee('SNVIEWONLY')
            ->assertDontSee('Reboot')
            ->assertSee('Riwayat');
    }

    public function test_manage_admin_sees_reboot_button_with_a_confirm_dialog(): void
    {
        $tenant = Tenant::factory()->create();
        CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(CpeDeviceIndex::class)
            ->assertSee('Reboot');

        // wire:confirm must exist AND its message must actually mention the
        // real-world impact — a bare "yakin?" would technically satisfy
        // "a confirm dialog exists" without satisfying the actual planning
        // requirement.
        $html->assertSeeHtml('wire:confirm=');
        $html->assertSeeHtml('terputus sebentar');
    }

    public function test_reboot_flashes_an_honest_not_yet_confirmed_message(): void
    {
        Http::fake([
            'genieacs-nbi:7557/devices/*/tasks*' => Http::response(['_id' => 'genieacs-task-ui-1'], 202),
        ]);
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'genieacs_device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B']);

        // Livewire::test() calls run through their own internal request
        // lifecycle — session() read from the outer PHPUnit process isn't
        // reliable here (no prior art for this in the repo). Assert against
        // the component's own re-rendered output instead, which is what a
        // real browser session actually shows.
        Livewire::actingAs($this->admin($tenant))
            ->test(CpeDeviceIndex::class)
            ->call('reboot', $device->id)
            ->assertSee('terkirim')
            ->assertSee('berikutnya')
            ->assertDontSee('Berhasil reboot');
    }

    public function test_reboot_failure_flashes_a_red_error_message_not_the_honest_status_message(): void
    {
        $tenant = Tenant::factory()->create();
        // No genieacs_device_id — guaranteed delivery failure.
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'genieacs_device_id' => null]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeDeviceIndex::class)
            ->call('reboot', $device->id)
            ->assertSee('GAGAL')
            ->assertDontSee('Perintah terkirim');
    }

    public function test_wifi_modal_requires_at_least_one_field(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeDeviceIndex::class)
            ->call('openWifiModal', $device->id)
            ->set('wifiSsid', '')
            ->set('wifiPassword', '')
            ->call('submitWifi')
            ->assertHasErrors(['wifiSsid', 'wifiPassword']);
    }

    public function test_wifi_modal_submits_and_flashes_honest_message(): void
    {
        Http::fake([
            'genieacs-nbi:7557/devices/*/tasks*' => Http::response(['_id' => 'genieacs-task-ui-2'], 202),
            'genieacs-nbi:7557/devices*' => Http::response([[
                '_id' => 'F86CE1-F663NV3a-ZICG296C2E7B',
                '_deviceId' => ['_OUI' => 'F86CE1', '_ProductClass' => 'F663NV3a', '_SerialNumber' => 'ZICG296C2E7B'],
            ]], 200),
        ]);
        CpeParameterMap::factory()->create([
            'oui' => 'F86CE1',
            'product_class' => 'F663NV3a',
            'parameter_key' => 'wifi_ssid',
            'parameter_path' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
        ]);
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'genieacs_device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B']);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(CpeDeviceIndex::class)
            ->call('openWifiModal', $device->id)
            ->set('wifiSsid', 'RumahBaru')
            ->call('submitWifi');

        $component->assertSet('showWifiModal', false);
        $this->assertDatabaseHas('cpe_action_logs', ['cpe_device_id' => $device->id, 'status' => 'delivered']);
        $component->assertSee('terkirim');
    }

    public function test_history_modal_shows_this_devices_logs(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);
        $otherDevice = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        CpeActionLog::factory()->for($device, 'cpeDevice')->create([
            'tenant_id' => $tenant->id,
            'action_type' => 'reboot',
        ]);
        CpeActionLog::factory()->for($otherDevice, 'cpeDevice')->create([
            'tenant_id' => $tenant->id,
            'action_type' => 'reboot',
            'genieacs_task_id' => 'should-not-appear',
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeDeviceIndex::class)
            ->call('openHistoryModal', $device->id)
            ->assertSee('Terkirim ke GenieACS')
            ->assertDontSee('should-not-appear');
    }
}
