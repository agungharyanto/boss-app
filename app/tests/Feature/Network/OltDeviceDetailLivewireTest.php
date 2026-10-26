<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\OltDeviceDetail;
use App\Models\Nas;
use App\Models\OltDevice;
use App\Models\OltManufacturer;
use App\Models\OltModel;
use App\Models\OltUplinkPort;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\OltUplinkService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.23.5 Bagian 2 — halaman detail OLT. OltUplinkService di-mock untuk
 * aksi refresh (tidak menyentuh OLT sungguhan).
 */
class OltDeviceDetailLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function adminAndOlt(): array
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $olt = OltDevice::factory()->create(['nas_id' => $nas->id, 'tenant_id' => $tenant->id]);

        return [$admin, $olt];
    }

    public function test_non_admin_cannot_mount(): void
    {
        [, $olt] = $this->adminAndOlt();
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($user)
            ->test(OltDeviceDetail::class, ['oltDevice' => $olt])
            ->assertForbidden();
    }

    public function test_uplink_and_provisioning_tabs_are_enabled_uplink_default(): void
    {
        [$admin, $olt] = $this->adminAndOlt();

        $component = Livewire::actingAs($admin)->test(OltDeviceDetail::class, ['oltDevice' => $olt])
            ->assertSet('activeTab', 'uplink');

        $tabs = collect($component->instance()->tabs());
        $this->assertTrue($tabs->firstWhere('key', 'uplink')['enabled']);
        $this->assertTrue($tabs->firstWhere('key', 'provisioning')['enabled']);
        // Hanya uplink + provisioning yang enabled sprint ini.
        $this->assertCount(2, $tabs->where('enabled', true));
    }

    public function test_cannot_switch_to_a_disabled_tab(): void
    {
        [$admin, $olt] = $this->adminAndOlt();

        Livewire::actingAs($admin)->test(OltDeviceDetail::class, ['oltDevice' => $olt])
            ->call('selectTab', 'details')   // disabled
            ->assertSet('activeTab', 'uplink'); // tetap uplink
    }

    public function test_shows_cached_uplink_ports(): void
    {
        [$admin, $olt] = $this->adminAndOlt();
        OltUplinkPort::create([
            'olt_device_id' => $olt->id, 'port_name' => 'gei_1/19/4',
            'admin_state' => 'up', 'tagged_vlans' => '9-10,172',
        ]);

        Livewire::actingAs($admin)->test(OltDeviceDetail::class, ['oltDevice' => $olt])
            ->assertSee('gei_1/19/4')
            ->assertSee('9-10,172');
    }

    public function test_refresh_delegates_to_service_and_shows_notice(): void
    {
        [$admin, $olt] = $this->adminAndOlt();

        $mock = $this->createMock(OltUplinkService::class);
        $mock->expects($this->once())->method('refresh')->willReturn(new Collection([
            new OltUplinkPort(['port_name' => 'gei_1/19/4']),
        ]));
        $mock->method('cached')->willReturn(new Collection);
        $this->app->instance(OltUplinkService::class, $mock);

        Livewire::actingAs($admin)->test(OltDeviceDetail::class, ['oltDevice' => $olt])
            ->call('refreshUplink')
            ->assertSet('refreshError', null)
            ->assertSet('refreshNotice', fn ($m) => str_contains((string) $m, '1 port'));
    }

    public function test_refresh_surfaces_service_error(): void
    {
        [$admin, $olt] = $this->adminAndOlt();

        $mock = $this->createMock(OltUplinkService::class);
        $mock->method('refresh')->willThrowException(new \RuntimeException('OLT tak terjangkau'));
        $mock->method('cached')->willReturn(new Collection);
        $this->app->instance(OltUplinkService::class, $mock);

        Livewire::actingAs($admin)->test(OltDeviceDetail::class, ['oltDevice' => $olt])
            ->call('refreshUplink')
            ->assertSet('refreshError', fn ($m) => str_contains((string) $m, 'tak terjangkau'));
    }

    // --- Tab Provisioning (v0.23.8.6a) ---

    /** OLT dengan vendor yang mapped (sidecarVendorKey) + admin superadmin satu tenant. */
    private function adminAndMappedOlt(string $manufacturerName, string $modelName): array
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $mfr = OltManufacturer::factory()->create(['name' => $manufacturerName]);
        $model = OltModel::factory()->create(['olt_manufacturer_id' => $mfr->id, 'name' => $modelName]);
        $olt = OltDevice::factory()->create([
            'nas_id' => $nas->id, 'tenant_id' => $tenant->id, 'olt_model_id' => $model->id,
        ]);

        return [$admin, $olt];
    }

    public function test_provisioning_saves_channel_and_bind_onu_toggles_on_ctcom_olt(): void
    {
        [$admin, $olt] = $this->adminAndMappedOlt('HSGQ', 'HSGQ-E04ID');  // bind-onu paradigm

        Livewire::actingAs($admin)->test(OltDeviceDetail::class, ['oltDevice' => $olt])
            ->assertSet('usesUncfgParadigm', false)
            ->call('selectTab', 'provisioning')
            ->assertSet('activeTab', 'provisioning')
            ->assertSee('Mode admisi ONU')          // section blade benar-benar ter-render
            ->assertSee('Channel provisioning')
            ->set('requiresBindOnu', false)
            ->set('omciChannelEnabled', false)
            ->set('tr069ChannelEnabled', true)
            ->call('saveProvisioning')
            ->assertSet('provError', null)
            ->assertSet('provNotice', fn ($m) => $m !== null);

        $olt->refresh();
        $this->assertFalse($olt->requires_bind_onu);
        $this->assertFalse($olt->omci_channel_enabled);
        $this->assertTrue($olt->tr069_channel_enabled);
    }

    public function test_zte_olt_requires_bind_onu_is_disabled_and_never_changes(): void
    {
        [$admin, $olt] = $this->adminAndMappedOlt('ZTE', 'C300');  // uncfg paradigm
        $olt->forceFill(['requires_bind_onu' => null])->save();

        Livewire::actingAs($admin)->test(OltDeviceDetail::class, ['oltDevice' => $olt])
            ->assertSet('usesUncfgParadigm', true)
            ->set('requiresBindOnu', true)   // coba ubah — harus diabaikan service
            ->set('omciChannelEnabled', true)
            ->set('tr069ChannelEnabled', true)
            ->call('saveProvisioning')
            ->assertSet('provError', null);

        $olt->refresh();
        $this->assertNull($olt->requires_bind_onu);  // tetap null (tak berlaku)
    }

    public function test_at_least_one_channel_must_stay_active(): void
    {
        [$admin, $olt] = $this->adminAndMappedOlt('HSGQ', 'HSGQ-E04ID');
        $olt->forceFill(['omci_channel_enabled' => true, 'tr069_channel_enabled' => true])->save();

        Livewire::actingAs($admin)->test(OltDeviceDetail::class, ['oltDevice' => $olt])
            ->set('omciChannelEnabled', false)
            ->set('tr069ChannelEnabled', false)
            ->call('saveProvisioning')
            ->assertSet('provError', fn ($m) => str_contains((string) $m, 'satu channel'));

        $olt->refresh();
        $this->assertTrue($olt->omci_channel_enabled);   // tak berubah (validasi gagal)
        $this->assertTrue($olt->tr069_channel_enabled);
    }

    public function test_tenant_isolation_other_tenant_cannot_open_olt_detail_route(): void
    {
        [, $oltA] = $this->adminAndMappedOlt('HSGQ', 'HSGQ-E04ID');

        $tenantB = Tenant::factory()->create();
        $adminB = User::factory()->create(['tenant_id' => $tenantB->id]);
        $adminB->assignRole('superadmin');  // permission global, TAPI OLT di tenant lain

        // Route-model-binding + TenantScope → OLT tenant lain tak ter-resolve (404).
        $this->actingAs($adminB)
            ->get(route('web.olt-devices.show', $oltA))
            ->assertNotFound();
    }
}
