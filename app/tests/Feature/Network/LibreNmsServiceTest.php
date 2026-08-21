<?php

namespace Tests\Feature\Network;

use App\Exceptions\LibreNmsDataUnavailableException;
use App\Services\Network\LibreNmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Never hits the real LibreNMS API/rrdtool binary — every fixture below
 * mirrors the REAL response shapes confirmed empirically against the live
 * LibreNMS 26.8.1 install + real router/OLT devices during v0.8.2's
 * research phase (see CLAUDE.md's "Dashboard Monitoring (v0.8.2)"),
 * including the two real gotchas that phase found: `/health/{type}` takes
 * SINGULAR type values (`processor`/`mempool`, not `processors`/
 * `mempools`), and a sensor's actual reading only comes back when a
 * specific sensor_id is requested — listing without one returns metadata
 * only.
 */
class LibreNmsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): LibreNmsService
    {
        return new LibreNmsService('http://librenms-test/api/v0', 'fake-token', '/tmp/librenms-rrd-test');
    }

    public function test_list_devices_returns_sanitized_fields_only(): void
    {
        Http::fake([
            '*/devices' => Http::response([
                'status' => 'ok',
                'devices' => [
                    [
                        'device_id' => 1,
                        'hostname' => '144.79.52.0',
                        'sysName' => 'ro-x86-kaliwungu.bajastu.id',
                        'status' => true,
                        'uptime' => 282392,
                        // Real LibreNMS /devices responses also include
                        // plaintext SNMP community/auth fields — must never
                        // survive into listDevices()'s return shape.
                        'community' => 'tokia121314',
                        'authpass' => 'secret',
                    ],
                ],
            ], 200),
        ]);

        $devices = $this->service()->listDevices();

        $this->assertSame([
            'device_id' => 1,
            'hostname' => '144.79.52.0',
            'sys_name' => 'ro-x86-kaliwungu.bajastu.id',
            'status' => true,
            'uptime' => 282392,
        ], $devices[0]);
        $this->assertArrayNotHasKey('community', $devices[0]);
        $this->assertArrayNotHasKey('authpass', $devices[0]);
    }

    public function test_list_devices_is_cached_and_does_not_refetch_within_ttl(): void
    {
        Http::fake([
            '*/devices' => Http::response(['status' => 'ok', 'devices' => []], 200),
        ]);

        $this->service()->listDevices();
        $this->service()->listDevices();

        Http::assertSentCount(1);
    }

    public function test_get_availability_returns_all_durations(): void
    {
        Http::fake([
            '*/devices/1/availability' => Http::response([
                'status' => 'ok',
                'availability' => [
                    ['duration' => 86400, 'availability_perc' => '99.980000'],
                    ['duration' => 604800, 'availability_perc' => '99.950000'],
                    ['duration' => 2592000, 'availability_perc' => '99.900000'],
                    ['duration' => 31536000, 'availability_perc' => '99.800000'],
                ],
            ], 200),
        ]);

        $availability = $this->service()->getAvailability(1);

        $this->assertCount(4, $availability);
        $this->assertSame(['duration_seconds' => 86400, 'availability_percent' => 99.98], $availability[0]);
    }

    public function test_get_cpu_usage_iterates_multiple_sensors(): void
    {
        Http::fake([
            '*/devices/2/health/processor/*' => function ($request) {
                $sensorId = (int) basename($request->url());

                return Http::response([
                    'status' => 'ok',
                    'graphs' => [[
                        'processor_id' => $sensorId,
                        'processor_descr' => 'Processor',
                        'processor_usage' => $sensorId === 49 ? 2 : 5,
                    ]],
                ], 200);
            },
            '*/devices/2/health/processor' => Http::response([
                'status' => 'ok',
                'graphs' => [
                    ['sensor_id' => 49, 'desc' => 'PRWH Processor'],
                    ['sensor_id' => 50, 'desc' => 'PRWH Processor'],
                ],
            ], 200),
        ]);

        $readings = $this->service()->getCpuUsage(2);

        $this->assertCount(2, $readings);
        $this->assertSame(2.0, $readings[0]['usage_percent']);
        $this->assertSame(5.0, $readings[1]['usage_percent']);
    }

    public function test_get_cpu_usage_returns_empty_array_when_device_has_no_processor_sensor(): void
    {
        // Real, confirmed case: the HSGQ-E04ID OLT (device_id 3 in the
        // real fleet) exposes no CPU OID via SNMP at all.
        Http::fake([
            '*/devices/3/health/processor' => Http::response(['status' => 'ok', 'graphs' => [], 'count' => 0], 200),
        ]);

        $readings = $this->service()->getCpuUsage(3);

        $this->assertSame([], $readings);
    }

    public function test_get_memory_usage_returns_percent_for_single_sensor_device(): void
    {
        Http::fake([
            '*/devices/1/health/mempool/1' => Http::response([
                'status' => 'ok',
                'graphs' => [['mempool_id' => 1, 'mempool_descr' => 'main memory', 'mempool_perc' => 2]],
            ], 200),
            '*/devices/1/health/mempool' => Http::response([
                'status' => 'ok',
                'graphs' => [['sensor_id' => 1, 'desc' => 'main memory']],
            ], 200),
        ]);

        $readings = $this->service()->getMemoryUsage(1);

        $this->assertSame(2.0, $readings[0]['usage_percent']);
    }

    public function test_get_temperature_filters_global_sensor_list_by_device_and_class(): void
    {
        Http::fake([
            '*/resources/sensors' => Http::response([
                'status' => 'ok',
                'sensors' => [
                    ['sensor_id' => 1, 'device_id' => 1, 'sensor_class' => 'count', 'sensor_descr' => 'DHCP Leases', 'sensor_current' => 726],
                    ['sensor_id' => 10, 'device_id' => 2, 'sensor_class' => 'temperature', 'sensor_descr' => 'Card A', 'sensor_current' => 27],
                    ['sensor_id' => 11, 'device_id' => 2, 'sensor_class' => 'temperature', 'sensor_descr' => 'Card B', 'sensor_current' => 40],
                ],
            ], 200),
        ]);

        $temperatureDevice2 = $this->service()->getTemperature(2);
        $temperatureRouter = $this->service()->getTemperature(1);

        $this->assertCount(2, $temperatureDevice2);
        $this->assertSame(27.0, $temperatureDevice2[0]['value_celsius']);
        // Real, confirmed case: the router has no temperature sensor.
        $this->assertSame([], $temperatureRouter);
    }

    public function test_get_temperature_global_sensor_list_is_fetched_once_for_multiple_devices(): void
    {
        Http::fake([
            '*/resources/sensors' => Http::response([
                'status' => 'ok',
                'sensors' => [
                    ['sensor_id' => 10, 'device_id' => 2, 'sensor_class' => 'temperature', 'sensor_descr' => 'Card A', 'sensor_current' => 27],
                ],
            ], 200),
        ]);

        $service = $this->service();
        $service->getTemperature(1);
        $service->getTemperature(2);
        $service->getTemperature(4);

        Http::assertSentCount(1);
    }

    public function test_get_traffic_history_parses_rrdtool_xport_json(): void
    {
        Http::fake([
            '*/devices/1' => Http::response(['status' => 'ok', 'devices' => [['device_id' => 1, 'hostname' => '144.79.52.0']]], 200),
            '*/devices/1/ports*' => Http::response([
                'status' => 'ok',
                'ports' => [['port_id' => 1, 'ifName' => 'ether2 - OUT SW', 'ifOperStatus' => 'up']],
            ], 200),
        ]);

        Process::fake([
            '*rrdtool*xport*' => Process::result(output: json_encode([
                'about' => 'RRDtool graph JSON output',
                'meta' => ['start' => 1787294100, 'end' => 1787294700, 'step' => 300, 'legend' => ['In', 'Out']],
                'data' => [
                    [4149502.5254, 52045908.807],
                    [4692397.3814, 50822505.983],
                    [null, null],
                ],
            ])),
        ]);

        $series = $this->service()->getTrafficHistory(1, 'ether2 - OUT SW', 1800);

        $this->assertCount(3, $series);
        $this->assertSame(1787294100, $series[0]['timestamp']);
        $this->assertEqualsWithDelta(4149502.5254, $series[0]['in_bytes_per_second'], 0.001);
        $this->assertSame(1787294700, $series[2]['timestamp']);
        $this->assertNull($series[2]['in_bytes_per_second']);
    }

    public function test_get_traffic_history_throws_when_interface_not_found(): void
    {
        Http::fake([
            '*/devices/1' => Http::response(['status' => 'ok', 'devices' => [['device_id' => 1, 'hostname' => '144.79.52.0']]], 200),
            '*/devices/1/ports*' => Http::response(['status' => 'ok', 'ports' => []], 200),
        ]);

        $this->expectException(LibreNmsDataUnavailableException::class);

        $this->service()->getTrafficHistory(1, 'nonexistent-interface', 1800);
    }

    public function test_get_traffic_history_throws_when_rrdtool_fails(): void
    {
        Http::fake([
            '*/devices/1' => Http::response(['status' => 'ok', 'devices' => [['device_id' => 1, 'hostname' => '144.79.52.0']]], 200),
            '*/devices/1/ports*' => Http::response([
                'status' => 'ok',
                'ports' => [['port_id' => 1, 'ifName' => 'ether2', 'ifOperStatus' => 'up']],
            ], 200),
        ]);

        Process::fake([
            '*rrdtool*xport*' => Process::result(output: '', errorOutput: "ERROR: opening 'x': No such file or directory", exitCode: 1),
        ]);

        $this->expectException(LibreNmsDataUnavailableException::class);

        $this->service()->getTrafficHistory(1, 'ether2', 1800);
    }
}
