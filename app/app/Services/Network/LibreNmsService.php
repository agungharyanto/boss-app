<?php

namespace App\Services\Network;

use App\Exceptions\LibreNmsDataUnavailableException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Thin wrapper around LibreNMS's REST API (v0), plus one method
 * (getTrafficHistory) that bypasses the REST API entirely and reads RRD
 * files directly via `rrdtool xport --json` — see CLAUDE.md's "Dashboard
 * Monitoring (v0.8.2)" section for the full architecture rationale.
 *
 * Two real, empirically-confirmed gotchas this class exists to hide from
 * callers (both found by testing against the real installed LibreNMS
 * 26.8.1, not assumed from general LibreNMS docs):
 *
 * 1. `{hostname}/health/{type}/{sensor_id?}` (list_available_health_graphs
 *    in LibreNMS's own api_functions.inc.php) takes SINGULAR type values
 *    (`processor`, `mempool`, `storage`) — plural (`processors`/
 *    `mempools`) silently 500s (an unrelated catch-all route absorbs the
 *    request and chokes on an unrecognized $type). There is no
 *    `/devices/{id}/processors`-style route in this version at all.
 * 2. Calling `{hostname}/health/{type}` WITHOUT a sensor_id only returns
 *    {sensor_id, desc} metadata pairs, never the actual reading — the real
 *    value (`processor_usage`, `mempool_perc`, ...) only comes back when a
 *    specific sensor_id is also given, one HTTP call per sensor. A device
 *    can have several sensors of the same class (e.g. the ZTE C300 OLT has
 *    7 separate processor sensors, one per card) — getCpuUsage()/
 *    getMemoryUsage() iterate all of them rather than assuming exactly one.
 *
 * A device that genuinely has no sensor of a given class (confirmed real,
 * not a bug — e.g. the HSGQ-E04ID OLT exposes no CPU or temperature OID at
 * all via SNMP) is represented as an EMPTY ARRAY from getCpuUsage()/
 * getMemoryUsage()/getTemperature() — never an exception. An exception
 * (LibreNmsDataUnavailableException) means the LibreNMS API/rrdtool itself
 * failed — callers (Livewire components) must render these two cases
 * differently ("Tidak ada sensor" vs "Data monitoring tidak tersedia").
 *
 * Every method is cached in Redis (config('services.librenms.cache_ttl'),
 * default 45s) so the same widget appearing more than once per page (or,
 * later, also on the main Dashboard) doesn't multiply real hits to
 * LibreNMS. A failed call is never cached — Cache::remember() only stores
 * a value if its closure returns normally, so a transient LibreNMS outage
 * is retried on the very next call rather than "stuck" showing failure for
 * a full TTL window.
 */
class LibreNmsService
{
    private readonly string $baseUrl;

    private readonly ?string $apiToken;

    private readonly string $rrdDataDir;

    private readonly int $cacheTtl;

    public function __construct(?string $baseUrl = null, ?string $apiToken = null, ?string $rrdDataDir = null)
    {
        $this->baseUrl = $baseUrl ?? config('services.librenms.api_url');
        $this->apiToken = $apiToken ?? config('services.librenms.api_token');
        $this->rrdDataDir = $rrdDataDir ?? config('services.librenms.rrd_data_dir');
        $this->cacheTtl = (int) config('services.librenms.cache_ttl', 45);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['X-Auth-Token' => $this->apiToken])
            ->timeout(5);
    }

    /**
     * Device list + status/uptime — deliberately narrowed to a safe subset
     * of LibreNMS's own response (which also includes the device's plain-
     * text SNMP community/auth credentials in every `/devices` response —
     * never pass the raw payload through to a cache entry or a view).
     *
     * @return array<int, array{device_id: int, hostname: string, sys_name: ?string, status: bool, uptime: ?int}>
     */
    public function listDevices(): array
    {
        return Cache::remember('librenms:devices', $this->cacheTtl, function () {
            $devices = $this->http()->get('/devices')->throw()->json('devices') ?? [];

            return collect($devices)
                ->map(fn (array $d) => [
                    'device_id' => (int) $d['device_id'],
                    'hostname' => $d['hostname'],
                    'sys_name' => $d['sysName'] ?? null,
                    'status' => (bool) $d['status'],
                    'uptime' => $d['uptime'] ?? null,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array{duration_seconds: int, availability_percent: float}>
     */
    public function getAvailability(int $deviceId): array
    {
        return Cache::remember("librenms:device:{$deviceId}:availability", $this->cacheTtl, function () use ($deviceId) {
            $data = $this->http()->get("/devices/{$deviceId}/availability")->throw()->json('availability') ?? [];

            return collect($data)
                ->map(fn (array $a) => [
                    'duration_seconds' => (int) $a['duration'],
                    'availability_percent' => (float) $a['availability_perc'],
                ])
                ->values()
                ->all();
        });
    }

    /**
     * One entry per processor sensor (a device can have several — e.g. one
     * per line card). Empty array means "this device has no processor
     * sensor" (real, not an error) — see this class's own docblock.
     *
     * @return array<int, array{sensor_id: int, label: ?string, usage_percent: ?float}>
     */
    public function getCpuUsage(int $deviceId): array
    {
        return Cache::remember("librenms:device:{$deviceId}:cpu", $this->cacheTtl, function () use ($deviceId) {
            return $this->collectHealthSensorReadings(
                $deviceId,
                'processor',
                idField: 'processor_id',
                labelField: 'processor_descr',
                valueField: 'processor_usage',
                outKey: 'usage_percent',
            );
        });
    }

    /**
     * One entry per memory-pool sensor — same multi-sensor-per-device shape
     * as getCpuUsage(). Empty array means no mempool sensor on this device.
     *
     * @return array<int, array{sensor_id: int, label: ?string, usage_percent: ?float}>
     */
    public function getMemoryUsage(int $deviceId): array
    {
        return Cache::remember("librenms:device:{$deviceId}:memory", $this->cacheTtl, function () use ($deviceId) {
            return $this->collectHealthSensorReadings(
                $deviceId,
                'mempool',
                idField: 'mempool_id',
                labelField: 'mempool_descr',
                valueField: 'mempool_perc',
                outKey: 'usage_percent',
            );
        });
    }

    /**
     * Lists sensor_ids for $type (`processor`/`mempool`), then makes one
     * further call per sensor_id to get its actual reading — see this
     * class's own docblock, gotcha #2. `/health/{type}` legitimately
     * returning zero sensors is not an error (a `count: 0` response, not a
     * 4xx/5xx) — this returns [] in that case, same as every other
     * "no sensor of this class" path.
     *
     * @return array<int, array{sensor_id: int, label: ?string, usage_percent: ?float}>
     */
    private function collectHealthSensorReadings(
        int $deviceId,
        string $type,
        string $idField,
        string $labelField,
        string $valueField,
        string $outKey,
    ): array {
        $list = $this->http()->get("/devices/{$deviceId}/health/{$type}")->throw()->json('graphs') ?? [];

        return collect($list)
            ->map(function (array $entry) use ($deviceId, $type, $idField, $labelField, $valueField, $outKey) {
                $sensorId = $entry['sensor_id'];
                $detail = $this->http()
                    ->get("/devices/{$deviceId}/health/{$type}/{$sensorId}")
                    ->throw()
                    ->json('graphs.0');

                if ($detail === null) {
                    return null;
                }

                return [
                    'sensor_id' => (int) $detail[$idField],
                    'label' => $detail[$labelField] ?? null,
                    $outKey => isset($detail[$valueField]) ? (float) $detail[$valueField] : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Temperature sensors, filtered client-side from LibreNMS's GLOBAL
     * `/resources/sensors` endpoint — there is no per-device
     * `/devices/{id}/sensors` route in this version. The global sensor
     * list itself is cached ONCE (not per-device) so rendering a full
     * device table doesn't refetch the same global list once per row.
     * Empty array means this device has no temperature sensor (real, not
     * an error — e.g. the HSGQ-E04ID OLT and the router itself have none).
     *
     * @return array<int, array{sensor_id: int, label: ?string, value_celsius: ?float}>
     */
    public function getTemperature(int $deviceId): array
    {
        return collect($this->temperatureSensorsGlobal())
            ->filter(fn (array $s) => (int) $s['device_id'] === $deviceId)
            ->map(fn (array $s) => [
                'sensor_id' => (int) $s['sensor_id'],
                'label' => $s['sensor_descr'] ?? null,
                'value_celsius' => isset($s['sensor_current']) ? (float) $s['sensor_current'] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function temperatureSensorsGlobal(): array
    {
        return Cache::remember('librenms:sensors:temperature', $this->cacheTtl, function () {
            $sensors = $this->http()->get('/resources/sensors')->throw()->json('sensors') ?? [];

            return collect($sensors)
                ->filter(fn (array $s) => ($s['sensor_class'] ?? null) === 'temperature')
                ->values()
                ->all();
        });
    }

    /**
     * Interface list for a device — used both to populate a "choose
     * interface" selector and to resolve an ifName to LibreNMS's own
     * port_id for getTrafficHistory() below. `columns=` must be passed
     * explicitly — the default `/ports` response only contains `ifName`
     * (found by reading get_device_ports()'s own source, not documented
     * behavior).
     *
     * @return array<int, array{port_id: int, if_name: string, if_oper_status: ?string}>
     */
    public function listPorts(int $deviceId): array
    {
        return Cache::remember("librenms:device:{$deviceId}:ports", $this->cacheTtl, function () use ($deviceId) {
            $ports = $this->http()
                ->get("/devices/{$deviceId}/ports", ['columns' => 'port_id,ifName,ifOperStatus'])
                ->throw()
                ->json('ports') ?? [];

            return collect($ports)
                ->map(fn (array $p) => [
                    'port_id' => (int) $p['port_id'],
                    'if_name' => $p['ifName'],
                    'if_oper_status' => $p['ifOperStatus'] ?? null,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * Raw traffic time-series (bytes/second, both directions), read
     * directly from LibreNMS's own RRD file via `rrdtool xport --json` —
     * LibreNMS's REST API has NO raw time-series JSON endpoint in this
     * version (its only `/ports/{ifname}/{type}` graph route renders an
     * SVG/PNG image, confirmed by reading api_get_graph()'s own source).
     *
     * Unlike getCpuUsage()/getMemoryUsage()/getTemperature(), there is no
     * "legitimately has no data" case here worth distinguishing from a
     * failure — every real port has a traffic RRD file the moment it's
     * been polled once, so any failure (device/interface not found, RRD
     * file missing, rrdtool erroring) is a genuine degraded-dependency
     * state and throws LibreNmsDataUnavailableException.
     *
     * @return array<int, array{timestamp: int, in_bytes_per_second: ?float, out_bytes_per_second: ?float}>
     *
     * @throws LibreNmsDataUnavailableException
     */
    public function getTrafficHistory(int $deviceId, string $ifName, int $rangeSeconds = 1800): array
    {
        $cacheKey = 'librenms:device:'.$deviceId.':traffic:'.md5($ifName).':'.$rangeSeconds;

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($deviceId, $ifName, $rangeSeconds) {
            $hostname = $this->resolveHostname($deviceId);

            if ($hostname === null) {
                throw new LibreNmsDataUnavailableException("Device LibreNMS #{$deviceId} tidak ditemukan.");
            }

            $portId = $this->resolvePortId($deviceId, $ifName);

            if ($portId === null) {
                throw new LibreNmsDataUnavailableException("Interface \"{$ifName}\" tidak ditemukan pada device #{$deviceId}.");
            }

            $rrdPath = rtrim($this->rrdDataDir, '/')."/{$hostname}/port-id{$portId}.rrd";

            $result = Process::timeout(10)->run([
                'rrdtool', 'xport', '--json',
                '-s', '-'.$rangeSeconds,
                '-e', 'now',
                "DEF:in={$rrdPath}:INOCTETS:AVERAGE",
                "DEF:out={$rrdPath}:OUTOCTETS:AVERAGE",
                'XPORT:in:In',
                'XPORT:out:Out',
            ]);

            if ($result->failed()) {
                throw new LibreNmsDataUnavailableException(
                    "rrdtool xport gagal untuk device #{$deviceId} interface \"{$ifName}\": ".trim($result->errorOutput())
                );
            }

            $decoded = json_decode($result->output(), true);

            if (! is_array($decoded) || ! isset($decoded['meta']['start'], $decoded['meta']['step'], $decoded['data'])) {
                throw new LibreNmsDataUnavailableException(
                    "Output rrdtool xport tidak sesuai format yang diharapkan untuk device #{$deviceId} interface \"{$ifName}\"."
                );
            }

            return $this->parseRrdXportJson($decoded);
        });
    }

    /**
     * @param  array{meta: array{start: int, step: int}, data: array<int, array{0: ?float, 1: ?float}>}  $decoded
     * @return array<int, array{timestamp: int, in_bytes_per_second: ?float, out_bytes_per_second: ?float}>
     */
    private function parseRrdXportJson(array $decoded): array
    {
        $start = (int) $decoded['meta']['start'];
        $step = (int) $decoded['meta']['step'];

        return collect($decoded['data'])
            ->values()
            ->map(fn (array $row, int $index) => [
                'timestamp' => $start + ($index * $step),
                'in_bytes_per_second' => $row[0] !== null ? (float) $row[0] : null,
                'out_bytes_per_second' => $row[1] !== null ? (float) $row[1] : null,
            ])
            ->all();
    }

    private function resolveHostname(int $deviceId): ?string
    {
        return Cache::remember("librenms:device:{$deviceId}:hostname", $this->cacheTtl, function () use ($deviceId) {
            $device = $this->http()->get("/devices/{$deviceId}")->throw()->json('devices.0');

            return $device['hostname'] ?? null;
        });
    }

    private function resolvePortId(int $deviceId, string $ifName): ?int
    {
        $port = collect($this->listPorts($deviceId))->firstWhere('if_name', $ifName);

        return $port['port_id'] ?? null;
    }
}
