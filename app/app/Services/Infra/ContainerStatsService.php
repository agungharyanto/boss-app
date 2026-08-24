<?php

namespace App\Services\Infra;

use App\Models\ContainerStatsHistory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v0.8.4 Bagian C — polls docker-stats-proxy (docker-compose.yml,
 * tecnativa/docker-socket-proxy, CONTAINERS=1 only, every mutating endpoint
 * family denied AT THE PROXY) over plain HTTP. Neither this class nor the
 * container it runs in (boss-app/boss-scheduler) ever mounts the real
 * Docker socket — see CLAUDE.md "Container Stats via docker-socket-proxy
 * (v0.8.4 Bagian C)" for the full architecture rationale.
 *
 * Same append-only-history pattern as CpeSignalHistoryService (v0.8.3):
 * syncAll() is the one write path, called by App\Console\Commands\
 * SyncContainerStats every 5 minutes. One container's fetch failure never
 * aborts the rest of the sweep — logged and skipped, same resilience
 * posture as CpeSignalHistoryService's own per-device try/catch.
 *
 * **CPU% formula matches `docker stats` exactly** (Docker's own
 * `calculateCPUPercentUnix`): `(cpu_delta / system_delta) * online_cpus *
 * 100`, where both `cpu_stats` AND `precpu_stats` come back in the SAME
 * `GET /containers/{id}/stats?stream=false` response — the Docker daemon
 * itself takes two internal samples ~1s apart before replying, confirmed
 * directly (precpu_stats.cpu_usage.total_usage genuinely differs from
 * cpu_stats.cpu_usage.total_usage in a real response), so no second HTTP
 * round trip is needed here to get a valid delta.
 *
 * **Memory usage subtracts page cache, matching `docker stats`'s "used"
 * figure, not raw cgroup usage** — this host runs a cgroup v2 kernel
 * (confirmed: `memory_stats.stats` has `inactive_file`, not the cgroup v1
 * `total_inactive_file` key), so `usage - stats.inactive_file` is used
 * (Docker CLI's own `calculateMemUsageUnixNoCache` logic) rather than the
 * raw `usage` figure, which would otherwise double-count reclaimable page
 * cache as if it were real application memory pressure.
 *
 * **Disk usage is `SizeRw` (the container's own writable layer), not
 * `SizeRootFs`** — fetched via ONE extra query-string flag on the same
 * `/containers/json` call already needed for the container list
 * (`?size=true`), not a separate endpoint. `SizeRootFs` mostly reflects
 * shared base-image layers common to every container built from the same
 * image and isn't a meaningful "how much has THIS container grown" signal;
 * `SizeRw` is.
 *
 * **Real measured timing on this server (27 containers, sequential, the
 * actual code path below) drove the 5-minute schedule interval** (see
 * routes/console.php) — `?size=true` itself is cheap (~0.2s for the whole
 * list), but the per-container `/stats?stream=false` loop took ~53s total
 * (~2s per container — the daemon's own internal two-sample wait, not
 * network latency) — comfortably inside a 5-minute budget with wide
 * margin (5-6x), but NOT safe at a 1-minute interval the way
 * VpnCheckNodeHealth/VpnSyncRouteFragments run — `Http::pool()` for
 * parallel per-container calls was considered and deliberately not used,
 * same "not worth the complexity for this data volume" call already made
 * for LibreNmsService's own per-sensor loop.
 *
 * **v0.8.3 — container grouping for the "Container BOSS App" section on
 * /monitoring.** `CONTAINER_GROUPS` is a deliberately EXPLICIT allow-list
 * per category (exact `container_name` values, matching `docker-compose.
 * yml`'s own `container_name:` entries) — not a regex/prefix guess (e.g.
 * matching on a `"boss-"` prefix would be wrong: `boss-nginx` belongs in
 * BOSS App Core, but nothing about the string itself distinguishes that
 * from, say, a hypothetical `boss-something-else` container that should
 * NOT be core). `groupFor()`'s fallback to `'Lainnya'` for any name not
 * explicitly listed is the ONLY non-explicit part of this design, and is
 * deliberate — it's what guarantees a brand-new container (e.g. a future
 * module's own service) is never silently hidden from the UI just because
 * nobody remembered to add it to a category yet; it lands in "Lainnya"
 * instead, visible but uncategorized, until someone explicitly places it.
 */
class ContainerStatsService
{
    /**
     * @var array<string, list<string>>
     */
    public const array CONTAINER_GROUPS = [
        'VPN' => ['openvpn', 'openvpn-node2', 'openvpn-node3', 'wireguard', 'wireguard-node2', 'wireguard-node3', 'l2tp'],
        'LibreNMS' => ['librenms', 'librenms-db', 'librenms-dispatcher', 'librenms-redis'],
        'BOSS App Core' => ['boss-app', 'boss-worker', 'boss-nginx', 'boss-postgresql', 'boss-redis', 'boss-scheduler', 'boss-whatsapp-worker'],
    ];

    public const string FALLBACK_GROUP = 'Lainnya';

    /**
     * The fixed display order for the /monitoring page's grouped sections —
     * VPN/LibreNMS/BOSS App Core in the order they were introduced across
     * this sprint cluster, "Lainnya" always last since it's the catch-all.
     *
     * @var list<string>
     */
    public const array GROUP_ORDER = ['VPN', 'LibreNMS', 'BOSS App Core', 'Lainnya'];

    public static function groupFor(string $containerName): string
    {
        foreach (self::CONTAINER_GROUPS as $group => $names) {
            if (in_array($containerName, $names, true)) {
                return $group;
            }
        }

        return self::FALLBACK_GROUP;
    }

    private readonly string $proxyUrl;

    public function __construct(?string $proxyUrl = null)
    {
        $this->proxyUrl = $proxyUrl ?? config('services.docker_stats.proxy_url');
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->proxyUrl)->timeout(10);
    }

    /**
     * @return array{recorded: int, failed: int, total: int}
     */
    public function syncAll(): array
    {
        try {
            $containers = $this->http()->get('/containers/json', ['size' => 'true'])->throw()->json();
        } catch (Throwable $e) {
            Log::warning("ContainerStatsService: gagal mengambil daftar container dari docker-stats-proxy — {$e->getMessage()}");

            return ['recorded' => 0, 'failed' => 0, 'total' => 0];
        }

        $result = ['recorded' => 0, 'failed' => 0, 'total' => count($containers)];
        $recordedAt = now();

        foreach ($containers as $container) {
            $name = ltrim($container['Names'][0] ?? $container['Id'] ?? 'unknown', '/');

            try {
                $stats = $this->http()->get("/containers/{$container['Id']}/stats", ['stream' => 'false'])->throw()->json();

                ContainerStatsHistory::create([
                    'container_name' => $name,
                    'cpu_percent' => $this->calculateCpuPercent($stats),
                    'memory_usage_mb' => $this->calculateMemoryUsageMb($stats['memory_stats'] ?? []),
                    'memory_limit_mb' => isset($stats['memory_stats']['limit']) ? $stats['memory_stats']['limit'] / 1024 / 1024 : null,
                    'network_rx_bytes' => $this->sumNetworkBytes($stats['networks'] ?? [], 'rx_bytes'),
                    'network_tx_bytes' => $this->sumNetworkBytes($stats['networks'] ?? [], 'tx_bytes'),
                    'disk_usage_mb' => isset($container['SizeRw']) ? $container['SizeRw'] / 1024 / 1024 : null,
                    'recorded_at' => $recordedAt,
                ]);

                $result['recorded']++;
            } catch (Throwable $e) {
                Log::warning("ContainerStatsService: gagal mengambil/menyimpan stats untuk container \"{$name}\" — {$e->getMessage()}");
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function calculateCpuPercent(array $stats): ?float
    {
        // Presence is checked BEFORE any arithmetic, deliberately — PHP's
        // arithmetic operators silently coerce a missing (null) operand to
        // 0 (`100 - null === 100`), so computing the deltas first and only
        // then checking `=== null` would never actually catch a missing
        // field — it would instead silently produce a wildly wrong
        // percentage from a partial payload. Caught by a test asserting
        // null cpu_percent for a response missing precpu_stats.
        // system_cpu_usage, not found by reasoning about the code alone.
        $totalUsage = $stats['cpu_stats']['cpu_usage']['total_usage'] ?? null;
        $preTotalUsage = $stats['precpu_stats']['cpu_usage']['total_usage'] ?? null;
        $systemUsage = $stats['cpu_stats']['system_cpu_usage'] ?? null;
        $preSystemUsage = $stats['precpu_stats']['system_cpu_usage'] ?? null;
        $onlineCpus = $stats['cpu_stats']['online_cpus'] ?? null;

        if ($totalUsage === null || $preTotalUsage === null || $systemUsage === null || $preSystemUsage === null || $onlineCpus === null) {
            return null;
        }

        $cpuDelta = $totalUsage - $preTotalUsage;
        $systemDelta = $systemUsage - $preSystemUsage;

        if ($systemDelta <= 0) {
            return null;
        }

        return ($cpuDelta / $systemDelta) * $onlineCpus * 100;
    }

    /**
     * @param  array<string, mixed>  $memoryStats
     */
    private function calculateMemoryUsageMb(array $memoryStats): ?float
    {
        if (! isset($memoryStats['usage'])) {
            return null;
        }

        $usage = $memoryStats['usage'];
        $cache = $memoryStats['stats']['inactive_file'] ?? $memoryStats['stats']['total_inactive_file'] ?? 0;

        $used = $cache < $usage ? $usage - $cache : $usage;

        return $used / 1024 / 1024;
    }

    /**
     * @param  array<string, array<string, mixed>>  $networks
     */
    private function sumNetworkBytes(array $networks, string $key): int
    {
        return (int) array_sum(array_column($networks, $key));
    }
}
