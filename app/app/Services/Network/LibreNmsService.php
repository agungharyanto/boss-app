<?php

namespace App\Services\Network;

use App\Exceptions\LibreNmsDataUnavailableException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
     * Onboards a generic SNMP device (v0.8.2 monitoring-fixes) — the same
     * `POST /devices` call already used to manually onboard the 3 real
     * OLTs during v0.8.1 (see CLAUDE.md's "LibreNMS OLT Onboarding"), now
     * codified here rather than left as an ad-hoc curl/UI action.
     * Deliberately never sends `force_add` — LibreNMS's own real
     * reachability/SNMP check decides pass or fail, same posture already
     * locked in for those 3 OLTs (never create a misleadingly "added" row
     * for a device that was never actually confirmed reachable).
     *
     * Confirmed by reading `add_device()`'s own source
     * (`includes/html/api_functions.inc.php`): a failure (unreachable,
     * SNMP timeout, host already exists, unsupported SNMP version) comes
     * back as a real HTTP 500 with LibreNMS's own human-readable message
     * in the JSON body — that message is passed through verbatim in the
     * thrown exception rather than paraphrased, so the UI can show
     * Agung exactly what LibreNMS itself says went wrong.
     *
     * `$displayName` is a genuine two-request flow, not one call — `POST
     * /devices`'s own field whitelist (confirmed by reading the same
     * source) has no `display` field at all, only `display_template` (a
     * templating feature, not a plain name). A custom display name needs a
     * SEPARATE `PATCH /devices/{id}` (`update_device()`), sent only when a
     * non-empty name was actually given and differs from the hostname.
     *
     * That PATCH deliberately targets the `display_template` field, NOT
     * `display` directly — a real bug found by testing this for real
     * (patching `display` returned a genuine HTTP 200 "has been updated"
     * response, but the value silently never changed, confirmed by reading
     * `devices.display` straight from librenms_db). Root cause: LibreNMS's
     * own `Device::$fillable` (app/Models/Device.php) does NOT include
     * `display` at all — only `display_template` — so `update_device()`'s
     * generic `$device->fill([$field => $data])->save()` silently drops
     * the assignment entirely for `display` (Eloquent mass-assignment
     * protection, no exception raised) while still reporting success,
     * since the resulting no-op `save()` still returns true.
     * `display_template` IS fillable, and `DeviceObserver::updating()`
     * regenerates `display` from it whenever `display_template` is dirty
     * (`SimpleTemplate::parse($this->display_template ?: ..., [...])` —
     * with no `{{ }}` placeholders in the string we send, this parses to
     * the literal string verbatim) — confirmed working end-to-end for
     * real against device #1 (patched to a test value, verified changed
     * in `librenms_db` directly, then reverted back to the original
     * `display_template = NULL` / `display = "144.79.52.0"`).
     *
     * Forgets the cached device list on success so `listDevices()` (and
     * therefore DeviceMonitoringList) reflects the new device on its very
     * next render, instead of waiting out the cache TTL.
     *
     * @return array{device_id: int, hostname: string}
     *
     * @throws LibreNmsDataUnavailableException on any add failure — the
     *                                          message is LibreNMS's own, not this class's.
     */
    public function addDevice(
        string $hostname,
        string $snmpVersion,
        ?string $community,
        int $port,
        ?string $displayName = null,
    ): array {
        $payload = array_filter([
            'hostname' => $hostname,
            'version' => $snmpVersion,
            'community' => $community,
            'port' => $port,
        ], fn ($value) => $value !== null && $value !== '');

        $response = $this->http()->post('/devices', $payload);

        if ($response->failed()) {
            throw new LibreNmsDataUnavailableException(
                $response->json('message') ?? "Gagal menambahkan device {$hostname} ke LibreNMS."
            );
        }

        $device = $response->json('devices.0') ?? [];
        $deviceId = (int) ($device['device_id'] ?? 0);

        if ($displayName !== null && $displayName !== '' && $displayName !== $hostname && $deviceId > 0) {
            $this->http()->patch("/devices/{$deviceId}", ['field' => 'display_template', 'data' => $displayName]);
        }

        Cache::forget('librenms:devices');

        return [
            'device_id' => $deviceId,
            'hostname' => $device['hostname'] ?? $hostname,
        ];
    }

    /**
     * v0.8.4 Bagian D — feeds DeviceEditForm's pre-filled fields. A
     * DELIBERATELY narrower subset than the raw `/devices/{id}` response
     * (which also includes SNMPv3 auth/crypto fields this UI never
     * exposes, per the same v1/v2c-only scope as AddMonitoringDeviceForm)
     * — but, unlike listDevices()'s own sanitization, DOES include the
     * real `community` value: an edit form legitimately needs the current
     * value to show/let an admin modify it, the same way LibreNMS's own
     * web UI does — this is not a "shown once, never again" secret the
     * way a Xendit key is, it's re-fetchable from LibreNMS itself by
     * anyone holding `monitoring.manage` regardless of what this form
     * shows.
     *
     * @return ?array{device_id: int, hostname: string, display_template: ?string, community: ?string, port: int, snmpver: string}
     */
    public function getEditableDevice(int $deviceId): ?array
    {
        $device = $this->http()->get("/devices/{$deviceId}")->throw()->json('devices.0');

        if ($device === null) {
            return null;
        }

        return [
            'device_id' => (int) $device['device_id'],
            'hostname' => $device['hostname'],
            'display_template' => $device['display_template'] ?? null,
            'community' => $device['community'] ?? null,
            'port' => (int) ($device['port'] ?? 161),
            'snmpver' => $device['snmpver'] ?? 'v2c',
        ];
    }

    /**
     * v0.8.4 Bagian D — Edit form on DeviceMonitoringList. Deliberately a
     * SMALL, explicit whitelist (`display_template`/`community`/`port`/
     * `snmpver`), not "whatever PATCH /devices/{id} accepts" — confirmed by
     * reading LibreNMS's own `App\Models\Device::$fillable`
     * (`app/Models/Device.php`) directly, which also allows `hostname`/
     * `ip`/SNMPv3 fields/etc., none of which this form exposes:
     * `hostname`/`ip` are deliberately excluded (changing a device's own
     * network identity is a materially bigger, riskier operation than
     * fixing a typo'd display name or rotating a community string — not
     * requested, not built), and SNMPv3 fields are excluded for the same
     * "only v1/v2c is a selectable option" reason `AddMonitoringDeviceForm`
     * already established. Confirmed live, safely, against the real router
     * (device #1) BEFORE trusting this: a same-value no-op PATCH of
     * `port`/`snmpver` returned a genuine `200 "Device fields have been
     * updated"` with zero effect on live polling.
     *
     * `field`/`data` are PARALLEL ARRAYS (LibreNMS's own multi-field PATCH
     * contract, confirmed live) — every key in $fields becomes one array
     * position in each. Forgets the cached device list on success, same
     * reasoning as addDevice().
     *
     * @param  array<string, string|int>  $fields  subset of display_template/community/port/snmpver
     *
     * @throws LibreNmsDataUnavailableException on any update failure
     */
    public function updateDevice(int $deviceId, array $fields): void
    {
        $allowed = ['display_template', 'community', 'port', 'snmpver'];
        $fields = array_intersect_key($fields, array_flip($allowed));

        if ($fields === []) {
            return;
        }

        $response = $this->http()->patch("/devices/{$deviceId}", [
            'field' => array_keys($fields),
            'data' => array_values($fields),
        ]);

        if ($response->failed()) {
            throw new LibreNmsDataUnavailableException(
                $response->json('message') ?? "Gagal mengubah device #{$deviceId} di LibreNMS."
            );
        }

        Cache::forget('librenms:devices');
        Cache::forget("librenms:device:{$deviceId}:hostname");
    }

    /**
     * v0.8.4 Bagian D — Remove button on DeviceMonitoringList, gated behind
     * `wire:confirm` in the Blade view (destructive — LibreNMS's own
     * `delete_device()` also drops that device's RRD history and port/
     * sensor rows, not just the device row itself).
     *
     * @throws LibreNmsDataUnavailableException on any delete failure
     */
    public function deleteDevice(int $deviceId): void
    {
        $response = $this->http()->delete("/devices/{$deviceId}");

        if ($response->failed()) {
            throw new LibreNmsDataUnavailableException(
                $response->json('message') ?? "Gagal menghapus device #{$deviceId} dari LibreNMS."
            );
        }

        Cache::forget('librenms:devices');
        Cache::forget("librenms:device:{$deviceId}:hostname");
    }

    /**
     * v0.8.4 — syslog entries for one device, via LibreNMS's own
     * `GET /logs/syslog/{device_id}` (list_logs, includes.html/
     * api_functions.inc.php) rather than a direct librenms_db query — same
     * "use the REST API when one exists" posture as every other read in
     * this class; `getTrafficHistory()`'s direct RRD file read is the one
     * exception, made only because no API alternative exists there at all.
     *
     * `$level` filters client-side (0-7, the syslog table's own numeric
     * severity column — 4=warning, 6=info, 7=debug, etc.) since
     * `list_logs()` has no server-side level/severity filter parameter —
     * confirmed by reading its own source, not assumed. There is no
     * "topic" filter at all: RouterOS's own topics (ppp/pppoe/system/...)
     * are never persisted anywhere in LibreNMS's `syslog` table schema
     * (only facility/priority/level/tag/program/msg are) — a topic-based
     * filter here would have nothing real to filter against.
     *
     * @return array<int, array{timestamp: ?string, host: ?string, program: ?string, level: ?int, msg: ?string}>
     */
    public function getSyslog(int $deviceId, int $limit = 50, ?int $level = null): array
    {
        return Cache::remember("librenms:syslog:{$deviceId}:{$limit}:{$level}", $this->cacheTtl, function () use ($deviceId, $limit, $level) {
            $rows = $this->http()
                ->get("/logs/syslog/{$deviceId}", ['limit' => $limit, 'sortorder' => 'DESC'])
                ->throw()
                ->json('logs') ?? [];

            if ($level !== null) {
                $rows = array_values(array_filter(
                    $rows,
                    fn (array $r) => isset($r['level']) && (int) $r['level'] === $level,
                ));
            }

            return array_map(fn (array $r) => [
                'timestamp' => $r['timestamp'] ?? null,
                'host' => $r['hostname'] ?? $r['sysName'] ?? null,
                'program' => $r['program'] ?? null,
                'level' => isset($r['level']) ? (int) $r['level'] : null,
                'msg' => $r['msg'] ?? null,
            ], $rows);
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
    public function getTrafficHistory(int $deviceId, string $ifName, int $rangeSeconds = 1800, ?Carbon $endAt = null): array
    {
        $cacheKey = 'librenms:device:'.$deviceId.':traffic:'.md5($ifName).':'.$rangeSeconds.':'.($endAt?->getTimestamp() ?? 'now');

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($deviceId, $ifName, $rangeSeconds, $endAt) {
            $hostname = $this->resolveHostname($deviceId);

            if ($hostname === null) {
                throw new LibreNmsDataUnavailableException("Device LibreNMS #{$deviceId} tidak ditemukan.");
            }

            $portId = $this->resolvePortId($deviceId, $ifName);

            if ($portId === null) {
                throw new LibreNmsDataUnavailableException("Interface \"{$ifName}\" tidak ditemukan pada device #{$deviceId}.");
            }

            $rrdPath = rtrim($this->rrdDataDir, '/')."/{$hostname}/port-id{$portId}.rrd";

            $result = Process::timeout(10)->run(array_merge(
                ['rrdtool', 'xport', '--json'],
                $this->xportTimeWindowArgs($rangeSeconds, $endAt),
                [
                    "DEF:in={$rrdPath}:INOCTETS:AVERAGE",
                    "DEF:out={$rrdPath}:OUTOCTETS:AVERAGE",
                    'XPORT:in:In',
                    'XPORT:out:Out',
                ],
            ));

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

    /**
     * v0.8.4 Bagian D — "Riwayat" modal for a device's CPU sensor, same
     * `rrdtool xport --json` mechanism as getTrafficHistory() above, not a
     * new one. The RRD filename is NOT a fixed `processor-hr-{id}.rrd`
     * pattern (that was an initial assumption disproven by directly
     * inspecting real files on this server) — it's
     * `processor-{processor_type}-{processor_index}.rrd`, vendor-driver-
     * specific (e.g. `processor-zxa10-1.1.3.rrd` for a ZTE OLT,
     * `processor-vrp-16842753.rrd` for Huawei VRP) — `processor_type`/
     * `processor_index` come from the SAME per-sensor detail call
     * collectHealthSensorReadings() already makes
     * (`/devices/{id}/health/processor/{sensor_id}`), confirmed
     * byte-for-byte against real files for all 7 sensors on a real ZTE
     * C300 OLT before trusting this pattern.
     *
     * @return array<int, array{timestamp: int, value: ?float}>
     *
     * @throws LibreNmsDataUnavailableException
     */
    public function getCpuHistory(int $deviceId, int $sensorId, int $rangeSeconds = 1800, ?Carbon $endAt = null): array
    {
        $cacheKey = "librenms:device:{$deviceId}:cpu-history:{$sensorId}:{$rangeSeconds}:".($endAt?->getTimestamp() ?? 'now');

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($deviceId, $sensorId, $rangeSeconds, $endAt) {
            $hostname = $this->resolveHostname($deviceId);

            if ($hostname === null) {
                throw new LibreNmsDataUnavailableException("Device LibreNMS #{$deviceId} tidak ditemukan.");
            }

            $detail = $this->http()->get("/devices/{$deviceId}/health/processor/{$sensorId}")->throw()->json('graphs.0');

            if ($detail === null) {
                throw new LibreNmsDataUnavailableException("Sensor processor #{$sensorId} tidak ditemukan pada device #{$deviceId}.");
            }

            $rrdPath = rtrim($this->rrdDataDir, '/')."/{$hostname}/processor-{$detail['processor_type']}-{$detail['processor_index']}.rrd";

            return $this->xportSingleSeries(
                $rrdPath,
                ["DEF:usage={$rrdPath}:usage:AVERAGE"],
                'usage',
                $rangeSeconds,
                "device #{$deviceId} processor sensor #{$sensorId}",
                $endAt,
            );
        });
    }

    /**
     * Same mechanism as getCpuHistory() above, but the mempool RRD file
     * only stores raw `used`/`free` datasources (confirmed via `rrdtool
     * info` against a real file — NOT a `perc` datasource, unlike what the
     * live API's own `mempool_perc` field might suggest) — the percentage
     * shown here is computed at export time via an rrdtool CDEF
     * (`used / (used+free) * 100`), matching what `getMemoryUsage()`'s
     * live value already represents. Filename pattern:
     * `mempool-{mempool_type}-{mempool_class}-{mempool_index}.rrd`
     * (e.g. `mempool-zxa10-system-1.1.3.rrd`), confirmed the same way as
     * the processor pattern above.
     *
     * @return array<int, array{timestamp: int, value: ?float}>
     *
     * @throws LibreNmsDataUnavailableException
     */
    public function getMemoryHistory(int $deviceId, int $sensorId, int $rangeSeconds = 1800, ?Carbon $endAt = null): array
    {
        $cacheKey = "librenms:device:{$deviceId}:memory-history:{$sensorId}:{$rangeSeconds}:".($endAt?->getTimestamp() ?? 'now');

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($deviceId, $sensorId, $rangeSeconds, $endAt) {
            $hostname = $this->resolveHostname($deviceId);

            if ($hostname === null) {
                throw new LibreNmsDataUnavailableException("Device LibreNMS #{$deviceId} tidak ditemukan.");
            }

            $detail = $this->http()->get("/devices/{$deviceId}/health/mempool/{$sensorId}")->throw()->json('graphs.0');

            if ($detail === null) {
                throw new LibreNmsDataUnavailableException("Sensor mempool #{$sensorId} tidak ditemukan pada device #{$deviceId}.");
            }

            $rrdPath = rtrim($this->rrdDataDir, '/')."/{$hostname}/mempool-{$detail['mempool_type']}-{$detail['mempool_class']}-{$detail['mempool_index']}.rrd";

            return $this->xportSingleSeries(
                $rrdPath,
                [
                    "DEF:used={$rrdPath}:used:AVERAGE",
                    "DEF:free={$rrdPath}:free:AVERAGE",
                    'CDEF:percent=used,used,free,+,/,100,*',
                ],
                'percent',
                $rangeSeconds,
                "device #{$deviceId} mempool sensor #{$sensorId}",
                $endAt,
            );
        });
    }

    /**
     * Same mechanism as getCpuHistory()/getMemoryHistory() above. Filename
     * pattern: `sensor-temperature-{sensor_type}-{sensor_index}.rrd`
     * (e.g. `sensor-temperature-zxa10-1.1.0.rrd`), single `sensor`
     * datasource — confirmed the same way as the other two patterns.
     *
     * @return array<int, array{timestamp: int, value: ?float}>
     *
     * @throws LibreNmsDataUnavailableException
     */
    public function getTemperatureHistory(int $deviceId, int $sensorId, int $rangeSeconds = 1800, ?Carbon $endAt = null): array
    {
        $cacheKey = "librenms:device:{$deviceId}:temperature-history:{$sensorId}:{$rangeSeconds}:".($endAt?->getTimestamp() ?? 'now');

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($deviceId, $sensorId, $rangeSeconds, $endAt) {
            $hostname = $this->resolveHostname($deviceId);

            if ($hostname === null) {
                throw new LibreNmsDataUnavailableException("Device LibreNMS #{$deviceId} tidak ditemukan.");
            }

            $detail = $this->http()->get("/devices/{$deviceId}/health/temperature/{$sensorId}")->throw()->json('graphs.0');

            if ($detail === null) {
                throw new LibreNmsDataUnavailableException("Sensor temperature #{$sensorId} tidak ditemukan pada device #{$deviceId}.");
            }

            $rrdPath = rtrim($this->rrdDataDir, '/')."/{$hostname}/sensor-temperature-{$detail['sensor_type']}-{$detail['sensor_index']}.rrd";

            return $this->xportSingleSeries(
                $rrdPath,
                ["DEF:sensor={$rrdPath}:sensor:AVERAGE"],
                'sensor',
                $rangeSeconds,
                "device #{$deviceId} temperature sensor #{$sensorId}",
                $endAt,
            );
        });
    }

    /**
     * v0.8.4 Bagian D — shared multi-sensor history fetch, extracted so
     * BOTH App\Livewire\Network\DeviceHistoryModal AND
     * MonitoringController::deviceHistory() (the REST API twin) call the
     * exact same logic rather than duplicating it (BOSS-006). Every sensor
     * of the given class gets its OWN series — never averaged away, see
     * DeviceHistoryModal's own docblock for why (a real device in this
     * fleet has up to 7 processor sensors, one per line card).
     *
     * A device with genuinely zero sensors of this class returns an EMPTY
     * array (real, not an error — same "no_sensor" distinction already
     * established for getCpuUsage()/getMemoryUsage()/getTemperature()).
     * One sensor's own history call failing is logged and dropped from the
     * result, NOT fatal to the others; only when EVERY sensor's history
     * call fails (despite the device having sensors) does this throw —
     * a genuine degraded-dependency state, not "no sensor".
     *
     * @return array<int, array{sensor_id: int, label: string, points: array<int, array{timestamp: int, value: ?float}>}>
     *
     * @throws LibreNmsDataUnavailableException if the sensor list call itself fails, or every sensor's history call fails
     * @throws \InvalidArgumentException if $metric isn't cpu/memory/temperature
     */
    public function getMetricHistory(int $deviceId, string $metric, int $rangeSeconds, ?Carbon $endAt = null): array
    {
        $sensors = match ($metric) {
            'cpu' => $this->getCpuUsage($deviceId),
            'memory' => $this->getMemoryUsage($deviceId),
            'temperature' => $this->getTemperature($deviceId),
            default => throw new \InvalidArgumentException("Metric tidak dikenal: \"{$metric}\". Gunakan cpu, memory, atau temperature."),
        };

        if ($sensors === []) {
            return [];
        }

        $series = [];

        foreach ($sensors as $sensor) {
            try {
                $points = match ($metric) {
                    'cpu' => $this->getCpuHistory($deviceId, $sensor['sensor_id'], $rangeSeconds, $endAt),
                    'memory' => $this->getMemoryHistory($deviceId, $sensor['sensor_id'], $rangeSeconds, $endAt),
                    'temperature' => $this->getTemperatureHistory($deviceId, $sensor['sensor_id'], $rangeSeconds, $endAt),
                };
            } catch (LibreNmsDataUnavailableException $e) {
                Log::warning("LibreNmsService::getMetricHistory: gagal ambil riwayat {$metric} sensor #{$sensor['sensor_id']} device #{$deviceId} — {$e->getMessage()}");

                continue;
            }

            $series[] = [
                'sensor_id' => $sensor['sensor_id'],
                'label' => $sensor['label'] ?? "Sensor #{$sensor['sensor_id']}",
                'points' => $points,
            ];
        }

        if ($series === []) {
            throw new LibreNmsDataUnavailableException("Riwayat {$metric} tidak tersedia untuk device #{$deviceId}.");
        }

        return $series;
    }

    /**
     * v0.8.3 (CLAUDE.md "Custom Date Range" section) — shared `-s`/`-e`
     * argument builder for every rrdtool xport call in this class,
     * including getTrafficHistory()'s own inline call. `$endAt === null`
     * (every pre-existing named-range caller) keeps the original relative
     * `-s -{rangeSeconds} -e now` shape byte-for-byte; the Custom Range
     * tab is the only caller that ever passes a real `$endAt`, in which
     * case both `-s`/`-e` become absolute Unix timestamps
     * (`$endAt - $rangeSeconds` / `$endAt`) — rrdtool accepts raw epoch
     * seconds for both flags natively, no AT-style string needed, and
     * still picks its own best-matching RRA/consolidation level for
     * whatever absolute window this resolves to, same as it already does
     * for the relative case.
     *
     * @return array<int, string>
     */
    private function xportTimeWindowArgs(int $rangeSeconds, ?Carbon $endAt): array
    {
        if ($endAt === null) {
            return ['-s', '-'.$rangeSeconds, '-e', 'now'];
        }

        $end = $endAt->getTimestamp();

        return ['-s', (string) ($end - $rangeSeconds), '-e', (string) $end];
    }

    /**
     * Shared `rrdtool xport --json` runner for a SINGLE-value series (CPU/
     * Memory/Temperature history) — getTrafficHistory() above has its own
     * inline two-column (in/out) version rather than sharing this, since
     * unifying a 1-column and 2-column parser wasn't worth the extra
     * indirection for just one caller. Both share xportTimeWindowArgs()
     * above for the actual `-s`/`-e` flags.
     *
     * @param  array<int, string>  $defAndCdefLines
     * @return array<int, array{timestamp: int, value: ?float}>
     *
     * @throws LibreNmsDataUnavailableException
     */
    private function xportSingleSeries(string $rrdPath, array $defAndCdefLines, string $xportVar, int $rangeSeconds, string $errorContext, ?Carbon $endAt = null): array
    {
        $result = Process::timeout(10)->run(array_merge(
            ['rrdtool', 'xport', '--json'],
            $this->xportTimeWindowArgs($rangeSeconds, $endAt),
            $defAndCdefLines,
            ["XPORT:{$xportVar}:{$xportVar}"],
        ));

        if ($result->failed()) {
            throw new LibreNmsDataUnavailableException(
                "rrdtool xport gagal untuk {$errorContext}: ".trim($result->errorOutput())
            );
        }

        $decoded = json_decode($result->output(), true);

        if (! is_array($decoded) || ! isset($decoded['meta']['start'], $decoded['meta']['step'], $decoded['data'])) {
            throw new LibreNmsDataUnavailableException(
                "Output rrdtool xport tidak sesuai format yang diharapkan untuk {$errorContext}."
            );
        }

        $start = (int) $decoded['meta']['start'];
        $step = (int) $decoded['meta']['step'];

        return collect($decoded['data'])
            ->values()
            ->map(fn (array $row, int $index) => [
                'timestamp' => $start + ($index * $step),
                'value' => $row[0] !== null ? (float) $row[0] : null,
            ])
            ->all();
    }
}
