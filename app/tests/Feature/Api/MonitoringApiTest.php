<?php

namespace Tests\Feature\Api;

use App\Models\ContainerStatsHistory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\LibreNmsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * v0.8.4 — read-only REST wrapper over LibreNmsService/
 * DeviceMonitoringSummaryService, the WhatsApp-bot integration foothold.
 * Same fake-LibreNmsService pattern as DeviceMonitoringListLivewireTest/
 * DeviceTrafficGraphLivewireTest, reused verbatim rather than reinvented,
 * since this controller delegates to the exact same services.
 */
class MonitoringApiTest extends TestCase
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

    private function nonMonitoring(): User
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('billing');

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

            public array $traffic;

            public bool $throwOnDevices = false;

            public function __construct(
                array $devices = [],
                array $cpu = [],
                array $memory = [],
                array $temperature = [],
                array $availability = [['duration_seconds' => 86400, 'availability_percent' => 99.98]],
                array $traffic = [],
                bool $throwOnDevices = false,
            ) {
                $this->devices = $devices;
                $this->cpu = $cpu;
                $this->memory = $memory;
                $this->temperature = $temperature;
                $this->availability = $availability;
                $this->traffic = $traffic;
                $this->throwOnDevices = $throwOnDevices;
            }

            public function listDevices(): array
            {
                if ($this->throwOnDevices) {
                    throw new RuntimeException('LibreNMS unreachable');
                }

                return $this->devices;
            }

            public function getCpuUsage(int $deviceId): array
            {
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

            public function getTrafficHistory(int $deviceId, string $ifName, int $rangeSeconds = 1800, ?Carbon $endAt = null): array
            {
                return $this->traffic;
            }
        };
    }

    public function test_devices_returns_one_averaged_row_per_device(): void
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

        $response = $this->actingAs($this->admin())->getJson('/api/v1/monitoring/devices');

        $response->assertOk();
        $response->assertJsonPath('data.0.device_id', 2);
        $response->assertJsonPath('data.0.hostname', 'c300.kaliwungu.bajastu.id');
        $response->assertJsonPath('data.0.cpu.state', 'ok');
        // json_encode() drops the trailing .0 from a whole-number float (no
        // JSON_PRESERVE_ZERO_FRACTION), so the decoded value is int 3, not
        // float 3.0 — assertJsonPath does a strict comparison.
        $response->assertJsonPath('data.0.cpu.value', 3);
    }

    public function test_devices_requires_monitoring_view_permission(): void
    {
        $this->app->instance(LibreNmsService::class, $this->fakeService());

        $this->actingAs($this->nonMonitoring())
            ->getJson('/api/v1/monitoring/devices')
            ->assertForbidden();
    }

    public function test_devices_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/v1/monitoring/devices')->assertUnauthorized();
    }

    public function test_device_traffic_requires_an_interface_param(): void
    {
        $this->app->instance(LibreNmsService::class, $this->fakeService());

        $response = $this->actingAs($this->admin())->getJson('/api/v1/monitoring/devices/2/traffic');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['interface']);
    }

    public function test_device_traffic_returns_the_raw_series(): void
    {
        $series = [
            ['timestamp' => 1000, 'in_bytes_per_second' => 5.0, 'out_bytes_per_second' => 10.0],
        ];
        $service = $this->fakeService(['traffic' => $series]);
        $this->app->instance(LibreNmsService::class, $service);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/monitoring/devices/2/traffic?interface=ether1&range=daily');

        $response->assertOk();
        $response->assertJsonPath('data.0.in_bytes_per_second', 5);
        $response->assertJsonPath('data.0.out_bytes_per_second', 10);
    }

    public function test_device_traffic_rejects_an_unknown_range(): void
    {
        $this->app->instance(LibreNmsService::class, $this->fakeService());

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/monitoring/devices/2/traffic?interface=ether1&range=not_a_real_range');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['range']);
    }

    public function test_containers_returns_only_the_latest_snapshot_per_container(): void
    {
        $older = now()->subMinutes(5);
        $latest = now();

        ContainerStatsHistory::factory()->create(['container_name' => 'boss-app', 'cpu_percent' => 1.0, 'recorded_at' => $older]);
        ContainerStatsHistory::factory()->create(['container_name' => 'boss-app', 'cpu_percent' => 2.0, 'recorded_at' => $latest]);
        ContainerStatsHistory::factory()->create(['container_name' => 'boss-worker', 'cpu_percent' => 3.0, 'recorded_at' => $latest]);

        $response = $this->actingAs($this->admin())->getJson('/api/v1/monitoring/containers');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.container_name', 'boss-app');
        $response->assertJsonPath('data.0.cpu_percent', 2);
        $response->assertJsonPath('data.1.container_name', 'boss-worker');
    }

    public function test_containers_with_no_data_returns_empty_array(): void
    {
        $response = $this->actingAs($this->admin())->getJson('/api/v1/monitoring/containers');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_containers_requires_monitoring_view_permission(): void
    {
        $this->actingAs($this->nonMonitoring())
            ->getJson('/api/v1/monitoring/containers')
            ->assertForbidden();
    }

    public function test_containers_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/v1/monitoring/containers')->assertUnauthorized();
    }

    // v0.8.4 Bagian D

    public function test_device_history_returns_the_multi_sensor_series(): void
    {
        $series = [
            ['sensor_id' => 49, 'label' => 'PRWH', 'points' => [['timestamp' => 1000, 'value' => 5.0]]],
            ['sensor_id' => 50, 'label' => 'GTGHG', 'points' => [['timestamp' => 1000, 'value' => 8.0]]],
        ];

        $service = new class($series) extends LibreNmsService
        {
            public function __construct(private readonly array $series) {}

            public function getMetricHistory(int $deviceId, string $metric, int $rangeSeconds, ?Carbon $endAt = null): array
            {
                return $this->series;
            }
        };
        $this->app->instance(LibreNmsService::class, $service);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/monitoring/devices/2/history?metric=cpu&range=daily');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.sensor_id', 49);
        $response->assertJsonPath('data.1.sensor_id', 50);
    }

    public function test_device_history_requires_a_metric_param(): void
    {
        $this->app->instance(LibreNmsService::class, $this->fakeService());

        $response = $this->actingAs($this->admin())->getJson('/api/v1/monitoring/devices/2/history');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['metric']);
    }

    public function test_device_history_rejects_an_unknown_metric(): void
    {
        $this->app->instance(LibreNmsService::class, $this->fakeService());

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/monitoring/devices/2/history?metric=not_a_real_metric');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['metric']);
    }

    public function test_device_history_requires_monitoring_view_permission(): void
    {
        $this->app->instance(LibreNmsService::class, $this->fakeService());

        $this->actingAs($this->nonMonitoring())
            ->getJson('/api/v1/monitoring/devices/2/history?metric=cpu')
            ->assertForbidden();
    }

    public function test_update_device_sends_the_whitelisted_fields(): void
    {
        $service = new class extends LibreNmsService
        {
            public ?array $fields = null;

            public function updateDevice(int $deviceId, array $fields): void
            {
                $this->fields = $fields;
            }
        };
        $this->app->instance(LibreNmsService::class, $service);

        $response = $this->actingAs($this->admin())->patchJson('/api/v1/monitoring/devices/2', [
            'display_name' => 'C300 Kaliwungu',
            'community' => 'newcommunity',
            'port' => 2161,
            'snmp_version' => 'v2c',
        ]);

        $response->assertOk();
        $this->assertSame([
            'display_template' => 'C300 Kaliwungu',
            'community' => 'newcommunity',
            'port' => 2161,
            'snmpver' => 'v2c',
        ], $service->fields);
    }

    public function test_update_device_requires_monitoring_manage_permission(): void
    {
        // Deliberately a direct permission grant, not the `noc` role —
        // noc also carries `.manage` per RolesAndPermissionsSeeder, which
        // would silently satisfy this guard without actually proving it.
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->givePermissionTo('monitoring.view');

        $this->app->instance(LibreNmsService::class, $this->fakeService());

        $this->actingAs($user)
            ->patchJson('/api/v1/monitoring/devices/2', ['community' => 'x'])
            ->assertForbidden();
    }

    public function test_destroy_device_calls_the_service_and_requires_manage_permission(): void
    {
        $service = new class extends LibreNmsService
        {
            public bool $deleted = false;

            public function deleteDevice(int $deviceId): void
            {
                $this->deleted = true;
            }
        };
        $this->app->instance(LibreNmsService::class, $service);

        $this->actingAs($this->admin())->deleteJson('/api/v1/monitoring/devices/2')->assertOk();
        $this->assertTrue($service->deleted);

        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->givePermissionTo('monitoring.view');

        $this->actingAs($user)->deleteJson('/api/v1/monitoring/devices/2')->assertForbidden();
    }
}
