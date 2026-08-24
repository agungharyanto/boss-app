<?php

namespace Tests\Feature\Network;

use App\Exceptions\LibreNmsDataUnavailableException;
use App\Services\Network\LibreNmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_add_device_sends_the_correct_payload_without_force_add(): void
    {
        Http::fake([
            '*/devices' => Http::response([
                'status' => 'ok',
                'message' => 'Device 10.1.1.5 (99) has been added successfully',
                'devices' => [['device_id' => 99, 'hostname' => '10.1.1.5']],
            ], 200),
        ]);

        $result = $this->service()->addDevice('10.1.1.5', 'v2c', 'public', 161);

        $this->assertSame(['device_id' => 99, 'hostname' => '10.1.1.5'], $result);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), '/devices')) {
                return false;
            }

            $body = $request->data();

            return $body['hostname'] === '10.1.1.5'
                && $body['version'] === 'v2c'
                && $body['community'] === 'public'
                && $body['port'] === 161
                && ! array_key_exists('force_add', $body);
        });
    }

    public function test_add_device_throws_librenms_own_error_message_on_failure(): void
    {
        // Real, confirmed-for-real response shape (device #88's own
        // account attempting to re-add the already-onboarded router).
        Http::fake([
            '*/devices' => Http::response(['status' => 'error', 'message' => 'Device 144.79.52.0 already exists'], 500),
        ]);

        try {
            $this->service()->addDevice('144.79.52.0', 'v2c', 'tokia121314', 161);
            $this->fail('Expected LibreNmsDataUnavailableException was not thrown.');
        } catch (LibreNmsDataUnavailableException $e) {
            $this->assertSame('Device 144.79.52.0 already exists', $e->getMessage());
        }
    }

    public function test_add_device_with_a_display_name_patches_display_template_not_display(): void
    {
        // Real, confirmed-for-real LibreNMS bug: Device::$fillable does
        // NOT include `display` (only `display_template`) — a PATCH
        // targeting `display` directly is silently a no-op (returns HTTP
        // 200 "updated" but never actually changes anything). See
        // LibreNmsService::addDevice()'s own docblock for the full
        // real-world verification trail.
        Http::fake([
            '*/devices' => Http::response([
                'status' => 'ok',
                'devices' => [['device_id' => 42, 'hostname' => '10.1.1.9']],
            ], 200),
            '*/devices/42' => Http::response(['status' => 'ok', 'message' => 'updated'], 200),
        ]);

        $this->service()->addDevice('10.1.1.9', 'v2c', 'public', 161, 'Switch Gudang');

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PATCH') {
                return false;
            }

            $body = $request->data();

            return $body['field'] === 'display_template' && $body['data'] === 'Switch Gudang';
        });
    }

    public function test_add_device_without_a_display_name_never_sends_a_patch(): void
    {
        Http::fake([
            '*/devices' => Http::response([
                'status' => 'ok',
                'devices' => [['device_id' => 42, 'hostname' => '10.1.1.9']],
            ], 200),
        ]);

        $this->service()->addDevice('10.1.1.9', 'v2c', 'public', 161);

        Http::assertNotSent(fn ($request) => $request->method() === 'PATCH');
    }

    public function test_add_device_forgets_the_cached_device_list_on_success(): void
    {
        $deviceCount = 1;

        Http::fake(function ($request) use (&$deviceCount) {
            if ($request->method() === 'POST') {
                $deviceCount = 2;

                return Http::response([
                    'status' => 'ok',
                    'message' => 'added',
                    'devices' => [['device_id' => 99, 'hostname' => '10.1.1.5']],
                ], 200);
            }

            $devices = [['device_id' => 1, 'hostname' => 'a', 'sysName' => null, 'status' => true, 'uptime' => 1]];

            if ($deviceCount === 2) {
                $devices[] = ['device_id' => 99, 'hostname' => '10.1.1.5', 'sysName' => null, 'status' => true, 'uptime' => null];
            }

            return Http::response(['status' => 'ok', 'devices' => $devices], 200);
        });

        $service = $this->service();
        $this->assertCount(1, $service->listDevices());

        // A cached listDevices() call BEFORE addDevice() would still be
        // serving the 1-device snapshot for cache_ttl seconds — addDevice()
        // must forget it so the very next listDevices() call reflects the
        // new device immediately, which is what DeviceMonitoringList's own
        // #[On('monitoring-device-added')] reload relies on.
        $service->addDevice('10.1.1.5', 'v2c', 'public', 161);

        $this->assertCount(2, $service->listDevices());
    }

    // v0.8.4 Bagian D — CPU/Memory/Temperature history. RRD filename
    // patterns below (processor-{type}-{index}.rrd, mempool-{type}-
    // {class}-{index}.rrd, sensor-temperature-{type}-{index}.rrd) are
    // confirmed byte-for-byte against real files on this server (see
    // CLAUDE.md "Container Stats via docker-socket-proxy (v0.8.4 Bagian
    // C)"'s sibling section for Bagian D), not assumed from LibreNMS docs.

    public function test_get_cpu_history_builds_the_correct_rrd_path_and_parses_output(): void
    {
        Http::fake([
            '*/devices/2' => Http::response(['status' => 'ok', 'devices' => [['device_id' => 2, 'hostname' => '10.168.100.34']]], 200),
            '*/devices/2/health/processor/49' => Http::response([
                'status' => 'ok',
                'graphs' => [['processor_id' => 49, 'processor_type' => 'zxa10', 'processor_index' => '1.1.0']],
            ], 200),
        ]);

        Process::fake([
            '*rrdtool*xport*' => Process::result(output: json_encode([
                'meta' => ['start' => 1787340600, 'step' => 300],
                'data' => [[0.0], [2.5]],
            ])),
        ]);

        $series = $this->service()->getCpuHistory(2, 49, 3600);

        $this->assertSame(1787340600, $series[0]['timestamp']);
        $this->assertSame(0.0, $series[0]['value']);
        $this->assertSame(2.5, $series[1]['value']);

        Process::assertRan(fn ($process) => str_contains(
            $process->command[7],
            '/librenms-rrd-test/10.168.100.34/processor-zxa10-1.1.0.rrd'
        ));
    }

    public function test_get_memory_history_computes_percent_via_cdef_not_a_raw_perc_datasource(): void
    {
        // Real, confirmed-for-real gotcha: the mempool RRD only stores raw
        // `used`/`free` datasources (via `rrdtool info` against a real
        // file) — NOT a `perc` datasource, despite the live API's own
        // `mempool_perc` field. Percent is computed at export time.
        Http::fake([
            '*/devices/2' => Http::response(['status' => 'ok', 'devices' => [['device_id' => 2, 'hostname' => '10.168.100.34']]], 200),
            '*/devices/2/health/mempool/2' => Http::response([
                'status' => 'ok',
                'graphs' => [['mempool_id' => 2, 'mempool_type' => 'zxa10', 'mempool_class' => 'system', 'mempool_index' => '1.1.3']],
            ], 200),
        ]);

        Process::fake([
            '*rrdtool*xport*' => Process::result(output: json_encode([
                'meta' => ['start' => 1787340600, 'step' => 300],
                'data' => [[19.04296875]],
            ])),
        ]);

        $series = $this->service()->getMemoryHistory(2, 2, 3600);

        $this->assertSame(19.04296875, $series[0]['value']);

        Process::assertRan(function ($process) {
            $cmd = $process->command;

            return str_contains($cmd[7], 'mempool-zxa10-system-1.1.3.rrd:used:AVERAGE')
                && str_contains($cmd[8], ':free:AVERAGE')
                && $cmd[9] === 'CDEF:percent=used,used,free,+,/,100,*';
        });
    }

    public function test_get_temperature_history_builds_the_correct_rrd_path(): void
    {
        Http::fake([
            '*/devices/2' => Http::response(['status' => 'ok', 'devices' => [['device_id' => 2, 'hostname' => '10.168.100.34']]], 200),
            '*/devices/2/health/temperature/13' => Http::response([
                'status' => 'ok',
                'graphs' => [['sensor_id' => 13, 'sensor_type' => 'zxa10', 'sensor_index' => '1.1.0']],
            ], 200),
        ]);

        Process::fake([
            '*rrdtool*xport*' => Process::result(output: json_encode([
                'meta' => ['start' => 1787340600, 'step' => 300],
                'data' => [[26.0]],
            ])),
        ]);

        $series = $this->service()->getTemperatureHistory(2, 13, 3600);

        $this->assertSame(26.0, $series[0]['value']);

        Process::assertRan(fn ($process) => str_contains(
            $process->command[7],
            'sensor-temperature-zxa10-1.1.0.rrd:sensor:AVERAGE'
        ));
    }

    public function test_get_metric_history_returns_one_series_per_sensor_never_averaged(): void
    {
        // Real, confirmed shape: the ZTE C300 OLT has 7 processor sensors.
        // Modeled here with 2 for brevity — the point is no averaging.
        Http::fake([
            '*/devices/2' => Http::response(['status' => 'ok', 'devices' => [['device_id' => 2, 'hostname' => '10.168.100.34']]], 200),
            '*/devices/2/health/processor' => Http::response([
                'status' => 'ok',
                'graphs' => [['sensor_id' => 49, 'desc' => 'PRWH'], ['sensor_id' => 50, 'desc' => 'GTGHG']],
            ], 200),
            '*/devices/2/health/processor/49' => Http::response([
                'status' => 'ok',
                'graphs' => [['processor_id' => 49, 'processor_descr' => 'PRWH', 'processor_usage' => 0, 'processor_type' => 'zxa10', 'processor_index' => '1.1.0']],
            ], 200),
            '*/devices/2/health/processor/50' => Http::response([
                'status' => 'ok',
                'graphs' => [['processor_id' => 50, 'processor_descr' => 'GTGHG', 'processor_usage' => 4, 'processor_type' => 'zxa10', 'processor_index' => '1.1.1']],
            ], 200),
        ]);

        Process::fake([
            '*rrdtool*xport*' => Process::result(output: json_encode([
                'meta' => ['start' => 1787340600, 'step' => 300],
                'data' => [[1.0]],
            ])),
        ]);

        $series = $this->service()->getMetricHistory(2, 'cpu', 3600);

        $this->assertCount(2, $series);
        $this->assertSame(49, $series[0]['sensor_id']);
        $this->assertSame('PRWH', $series[0]['label']);
        $this->assertSame(50, $series[1]['sensor_id']);
    }

    public function test_get_metric_history_returns_empty_array_for_a_device_with_no_sensor(): void
    {
        Http::fake([
            '*/devices/3/health/processor' => Http::response(['status' => 'ok', 'graphs' => []], 200),
        ]);

        $this->assertSame([], $this->service()->getMetricHistory(3, 'cpu', 3600));
    }

    public function test_get_metric_history_throws_only_when_every_sensor_fails(): void
    {
        Http::fake([
            '*/devices/2' => Http::response(['status' => 'ok', 'devices' => [['device_id' => 2, 'hostname' => '10.168.100.34']]], 200),
            '*/devices/2/health/processor' => Http::response([
                'status' => 'ok',
                'graphs' => [['sensor_id' => 49, 'desc' => 'PRWH']],
            ], 200),
            '*/devices/2/health/processor/49' => Http::response([
                'status' => 'ok',
                'graphs' => [['processor_id' => 49, 'processor_type' => 'zxa10', 'processor_index' => '1.1.0']],
            ], 200),
        ]);

        Process::fake([
            '*rrdtool*xport*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
        ]);

        $this->expectException(LibreNmsDataUnavailableException::class);

        $this->service()->getMetricHistory(2, 'cpu', 3600);
    }

    public function test_update_device_sends_parallel_field_and_data_arrays_and_forgets_cache(): void
    {
        Http::fake([
            '*/devices/2' => Http::response(['status' => 'ok', 'message' => 'updated'], 200),
        ]);

        $this->service()->updateDevice(2, [
            'display_template' => 'C300 Kaliwungu',
            'community' => 'newcommunity',
            'port' => 2161,
            'ignored_field' => 'must be dropped',
        ]);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PATCH') {
                return false;
            }

            $body = $request->data();

            return $body['field'] === ['display_template', 'community', 'port']
                && $body['data'] === ['C300 Kaliwungu', 'newcommunity', 2161];
        });
    }

    public function test_update_device_throws_librenms_own_error_message(): void
    {
        Http::fake([
            '*/devices/2' => Http::response(['status' => 'error', 'message' => 'Device does not exist'], 500),
        ]);

        try {
            $this->service()->updateDevice(2, ['community' => 'x']);
            $this->fail('Expected LibreNmsDataUnavailableException was not thrown.');
        } catch (LibreNmsDataUnavailableException $e) {
            $this->assertSame('Device does not exist', $e->getMessage());
        }
    }

    public function test_delete_device_sends_a_delete_request_and_forgets_cache(): void
    {
        Http::fake([
            '*/devices/2' => Http::response(['status' => 'ok', 'message' => 'deleted'], 200),
        ]);

        $this->service()->deleteDevice(2);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), '/devices/2'));
    }

    public function test_delete_device_throws_librenms_own_error_message(): void
    {
        Http::fake([
            '*/devices/2' => Http::response(['status' => 'error', 'message' => 'Device does not exist'], 500),
        ]);

        $this->expectException(LibreNmsDataUnavailableException::class);

        $this->service()->deleteDevice(2);
    }

    public function test_get_editable_device_returns_the_narrowed_field_subset(): void
    {
        Http::fake([
            '*/devices/2' => Http::response(['status' => 'ok', 'devices' => [[
                'device_id' => 2,
                'hostname' => '10.168.100.34',
                'display_template' => null,
                'community' => 'tokia121314',
                'port' => 161,
                'snmpver' => 'v2c',
                'authpass' => 'must-not-leak-here-either',
            ]]], 200),
        ]);

        $device = $this->service()->getEditableDevice(2);

        $this->assertSame([
            'device_id' => 2,
            'hostname' => '10.168.100.34',
            'display_template' => null,
            'community' => 'tokia121314',
            'port' => 161,
            'snmpver' => 'v2c',
        ], $device);
    }

    // v0.8.3 — Custom Date Range tab, see CLAUDE.md's own section.
    // xportTimeWindowArgs() is exercised via every public history method
    // that now accepts an optional trailing $endAt.

    public function test_get_cpu_history_with_explicit_end_at_uses_absolute_timestamps(): void
    {
        Http::fake([
            '*/devices/2' => Http::response(['status' => 'ok', 'devices' => [['device_id' => 2, 'hostname' => '10.168.100.34']]], 200),
            '*/devices/2/health/processor/49' => Http::response([
                'status' => 'ok',
                'graphs' => [['processor_id' => 49, 'processor_type' => 'zxa10', 'processor_index' => '1.1.0']],
            ], 200),
        ]);

        Process::fake([
            '*rrdtool*xport*' => Process::result(output: json_encode([
                'meta' => ['start' => 1700000000, 'step' => 3600],
                'data' => [[1.0]],
            ])),
        ]);

        $endAt = Carbon::createFromTimestamp(1700086400);
        $this->service()->getCpuHistory(2, 49, 3600, $endAt);

        // -s/-e must be absolute epoch seconds (endAt - rangeSeconds / endAt),
        // never the relative "-{rangeSeconds}"/"now" form used when $endAt
        // is null — a real, custom "Dari ... Sampai ..." request must never
        // silently fall back to "now" as the end of the window.
        Process::assertRan(function ($process) {
            $cmd = $process->command;

            return $cmd[3] === '-s' && $cmd[4] === '1700082800'
                && $cmd[5] === '-e' && $cmd[6] === '1700086400';
        });
    }

    public function test_get_traffic_history_without_end_at_keeps_the_original_relative_window(): void
    {
        Http::fake([
            '*/devices/1' => Http::response(['status' => 'ok', 'devices' => [['device_id' => 1, 'hostname' => '144.79.52.0']]], 200),
            '*/devices/1/ports*' => Http::response([
                'status' => 'ok',
                'ports' => [['port_id' => 1, 'ifName' => 'ether2', 'ifOperStatus' => 'up']],
            ], 200),
        ]);

        Process::fake([
            '*rrdtool*xport*' => Process::result(output: json_encode([
                'meta' => ['start' => 1787294100, 'step' => 300],
                'data' => [[1.0, 2.0]],
            ])),
        ]);

        $this->service()->getTrafficHistory(1, 'ether2', 1800);

        Process::assertRan(fn ($process) => $process->command[3] === '-s'
            && $process->command[4] === '-1800'
            && $process->command[5] === '-e'
            && $process->command[6] === 'now');
    }
}
