<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\OltDeviceDetail;
use App\Models\Nas;
use App\Models\OltDevice;
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

    public function test_only_uplink_tab_is_enabled_and_is_the_default(): void
    {
        [$admin, $olt] = $this->adminAndOlt();

        $component = Livewire::actingAs($admin)->test(OltDeviceDetail::class, ['oltDevice' => $olt])
            ->assertSet('activeTab', 'uplink');

        $tabs = collect($component->instance()->tabs());
        $this->assertTrue($tabs->firstWhere('key', 'uplink')['enabled']);
        // Semua tab lain disabled.
        $this->assertCount(1, $tabs->where('enabled', true));
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
}
