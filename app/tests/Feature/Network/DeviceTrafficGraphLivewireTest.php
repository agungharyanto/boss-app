<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\DeviceTrafficGraph;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\LibreNmsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class DeviceTrafficGraphLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function fakeService(array $ports = [], array $series = [], bool $throw = false): LibreNmsService
    {
        return new class($ports, $series, $throw) extends LibreNmsService
        {
            public function __construct(
                private readonly array $ports,
                private readonly array $series,
                private readonly bool $throw,
            ) {}

            public function listPorts(int $deviceId): array
            {
                return $this->ports;
            }

            public function getTrafficHistory(int $deviceId, string $ifName, int $rangeSeconds = 1800): array
            {
                if ($this->throw) {
                    throw new RuntimeException('LibreNMS unreachable');
                }

                return $this->series;
            }
        };
    }

    public function test_bare_mount_shows_empty_state_until_a_device_is_selected(): void
    {
        Livewire::actingAs($this->admin())
            ->test(DeviceTrafficGraph::class)
            ->assertSet('state', 'empty');
    }

    public function test_mount_with_device_id_auto_selects_the_up_interface_and_loads_series(): void
    {
        $service = $this->fakeService(
            ports: [
                ['port_id' => 1, 'if_name' => 'ether1', 'if_oper_status' => 'down'],
                ['port_id' => 2, 'if_name' => 'ether2', 'if_oper_status' => 'up'],
            ],
            series: [['timestamp' => 1000, 'in_bytes_per_second' => 5.0, 'out_bytes_per_second' => 10.0]],
        );
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceTrafficGraph::class, ['deviceId' => 1])
            ->assertSet('selectedIfName', 'ether2')
            ->assertSet('state', 'ok')
            ->assertSet('series.0.in_bytes_per_second', 5.0);
    }

    public function test_device_selected_event_from_sibling_component_loads_the_graph(): void
    {
        $service = $this->fakeService(
            ports: [['port_id' => 1, 'if_name' => 'ether1', 'if_oper_status' => 'up']],
            series: [['timestamp' => 1000, 'in_bytes_per_second' => 1.0, 'out_bytes_per_second' => 2.0]],
        );
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceTrafficGraph::class)
            ->assertSet('state', 'empty')
            ->dispatch('device-selected', deviceId: 1)
            ->assertSet('deviceId', 1)
            ->assertSet('state', 'ok');
    }

    public function test_device_selected_with_null_resets_to_empty_state(): void
    {
        $service = $this->fakeService(
            ports: [['port_id' => 1, 'if_name' => 'ether1', 'if_oper_status' => 'up']],
            series: [['timestamp' => 1000, 'in_bytes_per_second' => 1.0, 'out_bytes_per_second' => 2.0]],
        );
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceTrafficGraph::class, ['deviceId' => 1])
            ->assertSet('state', 'ok')
            ->dispatch('device-selected', deviceId: null)
            ->assertSet('state', 'empty')
            ->assertSet('deviceId', null);
    }

    public function test_rrdtool_failure_degrades_to_unavailable_state_without_throwing(): void
    {
        $service = $this->fakeService(
            ports: [['port_id' => 1, 'if_name' => 'ether1', 'if_oper_status' => 'up']],
            throw: true,
        );
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceTrafficGraph::class, ['deviceId' => 1])
            ->assertSet('state', 'unavailable');
    }

    public function test_changing_selected_interface_reloads_series(): void
    {
        $service = $this->fakeService(
            ports: [
                ['port_id' => 1, 'if_name' => 'ether1', 'if_oper_status' => 'up'],
                ['port_id' => 2, 'if_name' => 'ether2', 'if_oper_status' => 'up'],
            ],
            series: [['timestamp' => 1000, 'in_bytes_per_second' => 9.0, 'out_bytes_per_second' => 9.0]],
        );
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceTrafficGraph::class, ['deviceId' => 1])
            ->set('selectedIfName', 'ether2')
            ->assertSet('state', 'ok')
            ->assertSet('series.0.in_bytes_per_second', 9.0);
    }
}
