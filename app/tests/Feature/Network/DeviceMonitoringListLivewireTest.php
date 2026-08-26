<?php

namespace Tests\Feature\Network;

use App\Exceptions\LibreNmsDataUnavailableException;
use App\Livewire\Network\DeviceMonitoringList;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\LibreNmsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeviceMonitoringListLivewireTest extends TestCase
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
        $user->assignRole('superadmin');

        return $user;
    }

    private function fakeService(array $overrides = []): LibreNmsService
    {
        return new class(...$overrides) extends LibreNmsService
        {
            public array $devices;

            public array $cpu;

            public array $memory;

            public array $temperature;

            public array $availability;

            public bool $throwOnDevices = false;

            public bool $throwOnCpu = false;

            public function __construct(
                array $devices = [],
                array $cpu = [],
                array $memory = [],
                array $temperature = [],
                array $availability = [['duration_seconds' => 86400, 'availability_percent' => 99.98]],
                bool $throwOnDevices = false,
                bool $throwOnCpu = false,
            ) {
                $this->devices = $devices;
                $this->cpu = $cpu;
                $this->memory = $memory;
                $this->temperature = $temperature;
                $this->availability = $availability;
                $this->throwOnDevices = $throwOnDevices;
                $this->throwOnCpu = $throwOnCpu;
            }

            public function listDevices(): array
            {
                if ($this->throwOnDevices) {
                    throw new \RuntimeException('LibreNMS unreachable');
                }

                return $this->devices;
            }

            public function getCpuUsage(int $deviceId): array
            {
                if ($this->throwOnCpu) {
                    throw new \RuntimeException('LibreNMS unreachable');
                }

                return $this->cpu;
            }

            public function getMemoryUsage(int $deviceId): array
            {
                return $this->memory;
            }

            public function getTemperature(int $deviceId): array
            {
                return $this->temperature;
            }

            public function getAvailability(int $deviceId): array
            {
                return $this->availability;
            }
        };
    }

    public function test_renders_a_row_per_device_with_averaged_metrics(): void
    {
        $service = $this->fakeService([
            'devices' => [
                ['device_id' => 2, 'hostname' => 'c300.kaliwungu.bajastu.id', 'sys_name' => 'c300.kaliwungu.bajastu.id', 'status' => true, 'uptime' => 100000],
            ],
            'cpu' => [
                ['sensor_id' => 49, 'label' => 'Processor', 'usage_percent' => 2.0],
                ['sensor_id' => 50, 'label' => 'Processor', 'usage_percent' => 4.0],
            ],
        ]);
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceMonitoringList::class)
            ->assertSet('rows.0.device_id', 2)
            ->assertSet('rows.0.cpu.state', 'ok')
            ->assertSet('rows.0.cpu.value', 3.0);
    }

    public function test_device_with_no_sensor_shows_no_sensor_state_not_an_error(): void
    {
        // Real, confirmed case: HSGQ-E04ID (device_id 3) has no CPU sensor.
        $service = $this->fakeService([
            'devices' => [
                ['device_id' => 3, 'hostname' => 'olt-cileg', 'sys_name' => 'olt-cileg', 'status' => true, 'uptime' => 50000],
            ],
            'cpu' => [],
        ]);
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceMonitoringList::class)
            ->assertSet('rows.0.cpu.state', 'no_sensor')
            ->assertSet('rows.0.cpu.value', null);
    }

    public function test_one_devices_failing_metric_does_not_break_the_page(): void
    {
        $service = $this->fakeService([
            'devices' => [
                ['device_id' => 1, 'hostname' => '144.79.52.0', 'sys_name' => 'router', 'status' => true, 'uptime' => 1000],
            ],
            'throwOnCpu' => true,
        ]);
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceMonitoringList::class)
            ->assertSet('pageUnavailable', false)
            ->assertSet('rows.0.cpu.state', 'unavailable')
            ->assertSet('rows.0.availability.state', 'ok');
    }

    public function test_whole_device_list_failing_shows_page_level_degraded_state(): void
    {
        $service = $this->fakeService(['throwOnDevices' => true]);
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceMonitoringList::class)
            ->assertSet('pageUnavailable', true)
            ->assertSet('rows', []);
    }

    public function test_only_device_id_filter_narrows_to_one_row(): void
    {
        $service = $this->fakeService([
            'devices' => [
                ['device_id' => 1, 'hostname' => 'router', 'sys_name' => 'router', 'status' => true, 'uptime' => 1000],
                ['device_id' => 2, 'hostname' => 'c300', 'sys_name' => 'c300', 'status' => true, 'uptime' => 2000],
            ],
        ]);
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceMonitoringList::class, ['onlyDeviceId' => 2])
            ->assertCount('rows', 1)
            ->assertSet('rows.0.device_id', 2);
    }

    public function test_selecting_a_device_dispatches_device_selected_event(): void
    {
        $service = $this->fakeService([
            'devices' => [
                ['device_id' => 1, 'hostname' => 'router', 'sys_name' => 'router', 'status' => true, 'uptime' => 1000],
            ],
        ]);
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceMonitoringList::class)
            ->call('selectDevice', 1)
            ->assertDispatched('device-selected', deviceId: 1);
    }

    public function test_guest_without_permission_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($user)->test(DeviceMonitoringList::class)->assertForbidden();
    }

    // v0.8.4 Bagian D

    public function test_open_history_dispatches_the_event_a_sibling_modal_listens_for(): void
    {
        $this->app->instance(LibreNmsService::class, $this->fakeService());

        Livewire::actingAs($this->admin())
            ->test(DeviceMonitoringList::class)
            ->call('openHistory', 2, 'c300.kaliwungu')
            ->assertDispatched('device-history-requested', deviceId: 2, deviceName: 'c300.kaliwungu');
    }

    public function test_open_edit_dispatches_the_event_a_sibling_form_listens_for(): void
    {
        $this->app->instance(LibreNmsService::class, $this->fakeService());

        Livewire::actingAs($this->admin())
            ->test(DeviceMonitoringList::class)
            ->call('openEdit', 2)
            ->assertDispatched('device-edit-requested', deviceId: 2);
    }

    public function test_remove_device_reloads_the_list_on_success(): void
    {
        $service = new class extends LibreNmsService
        {
            public array $devices = [];

            public bool $deleted = false;

            public function listDevices(): array
            {
                return $this->devices;
            }

            public function deleteDevice(int $deviceId): void
            {
                $this->deleted = true;
                $this->devices = [];
            }
        };
        $service->devices = [
            ['device_id' => 2, 'hostname' => 'c300', 'sys_name' => 'c300', 'status' => true, 'uptime' => 1000],
        ];
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceMonitoringList::class)
            ->assertCount('rows', 1)
            ->call('removeDevice', 2)
            ->assertSet('rows', []);

        $this->assertTrue($service->deleted);
    }

    public function test_remove_device_shows_librenms_own_error_and_keeps_the_row(): void
    {
        $service = new class extends LibreNmsService
        {
            public function listDevices(): array
            {
                return [['device_id' => 2, 'hostname' => 'c300', 'sys_name' => 'c300', 'status' => true, 'uptime' => 1000]];
            }

            public function deleteDevice(int $deviceId): void
            {
                throw new LibreNmsDataUnavailableException('Device does not exist');
            }
        };
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceMonitoringList::class)
            ->call('removeDevice', 2)
            ->assertSet('removeErrorMessage', 'Device does not exist')
            ->assertCount('rows', 1);
    }

    public function test_remove_device_requires_monitoring_manage_permission(): void
    {
        // Deliberately NOT the `noc` role — both superadmin and noc
        // already carry `monitoring.manage` too (RolesAndPermissionsSeeder),
        // so a role-based test can't isolate `.view`-without-`.manage`.
        // A direct permission grant with no role at all proves the guard
        // is real, not accidentally satisfied by a role also carrying
        // `.manage`.
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->givePermissionTo('monitoring.view');

        $this->app->instance(LibreNmsService::class, $this->fakeService());

        Livewire::actingAs($user)
            ->test(DeviceMonitoringList::class)
            ->call('removeDevice', 2)
            ->assertForbidden();
    }
}
