<?php

namespace Tests\Feature\Network;

use App\Models\ContainerStatsHistory;
use App\Services\Infra\ContainerStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Never hits the real docker-stats-proxy container — fixtures mirror the
 * REAL response shapes confirmed empirically against the live
 * tecnativa/docker-socket-proxy container during v0.8.4 Bagian C's
 * verification (cgroup v2 keys — `inactive_file`, not the cgroup v1
 * `total_inactive_file`; `precpu_stats` genuinely differs from
 * `cpu_stats` in a real single `stream=false` response; `SizeRw` via
 * `?size=true` on `/containers/json`).
 */
class ContainerStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ContainerStatsService
    {
        return new ContainerStatsService('http://docker-stats-proxy-test:2375');
    }

    private function statsFixture(int $totalUsage, int $preTotalUsage, int $systemUsage, int $preSystemUsage): array
    {
        return [
            'cpu_stats' => [
                'cpu_usage' => ['total_usage' => $totalUsage],
                'system_cpu_usage' => $systemUsage,
                'online_cpus' => 6,
            ],
            'precpu_stats' => [
                'cpu_usage' => ['total_usage' => $preTotalUsage],
                'system_cpu_usage' => $preSystemUsage,
            ],
            'memory_stats' => [
                'usage' => 10428416,
                'stats' => ['inactive_file' => 36864],
                'limit' => 20722475008,
            ],
            'networks' => [
                'eth0' => ['rx_bytes' => 7684, 'tx_bytes' => 309168],
            ],
        ];
    }

    public function test_records_one_row_per_container_with_real_shaped_stats(): void
    {
        Http::fake([
            '*/containers/json*' => Http::response([
                ['Id' => 'abc123', 'Names' => ['/genieacs-cwmp'], 'SizeRw' => 28672, 'SizeRootFs' => 241197056],
            ], 200),
            '*/containers/abc123/stats*' => Http::response(
                $this->statsFixture(4766723601000, 4766662321000, 5744722480000000, 5744716500000000),
                200
            ),
        ]);

        $result = $this->service()->syncAll();

        $this->assertSame(['recorded' => 1, 'failed' => 0, 'total' => 1], $result);

        $row = ContainerStatsHistory::sole();
        $this->assertSame('genieacs-cwmp', $row->container_name);
        // (61280000 / 5980000000) * 6 * 100 ≈ 6.147...
        $this->assertEqualsWithDelta(6.15, $row->cpu_percent, 0.01);
        // (10428416 - 36864) / 1024 / 1024
        $this->assertEqualsWithDelta(9.91, $row->memory_usage_mb, 0.01);
        $this->assertEqualsWithDelta(19762.49, $row->memory_limit_mb, 0.01);
        $this->assertSame(7684, $row->network_rx_bytes);
        $this->assertSame(309168, $row->network_tx_bytes);
        $this->assertEqualsWithDelta(0.03, $row->disk_usage_mb, 0.01);
    }

    public function test_container_name_strips_leading_slash(): void
    {
        Http::fake([
            '*/containers/json*' => Http::response([
                ['Id' => 'x1', 'Names' => ['/boss-app']],
            ], 200),
            '*/containers/x1/stats*' => Http::response($this->statsFixture(2, 1, 2, 1), 200),
        ]);

        $this->service()->syncAll();

        $this->assertSame('boss-app', ContainerStatsHistory::sole()->container_name);
    }

    public function test_one_containers_stats_failure_does_not_abort_the_rest_of_the_sweep(): void
    {
        Http::fake([
            '*/containers/json*' => Http::response([
                ['Id' => 'good', 'Names' => ['/boss-app']],
                ['Id' => 'bad', 'Names' => ['/boss-worker']],
            ], 200),
            '*/containers/good/stats*' => Http::response($this->statsFixture(2, 1, 2, 1), 200),
            '*/containers/bad/stats*' => Http::response('boom', 500),
        ]);

        $result = $this->service()->syncAll();

        $this->assertSame(['recorded' => 1, 'failed' => 1, 'total' => 2], $result);
        $this->assertSame('boss-app', ContainerStatsHistory::sole()->container_name);
    }

    public function test_container_list_failure_records_nothing_and_does_not_throw(): void
    {
        Http::fake([
            '*/containers/json*' => Http::response('boom', 500),
        ]);

        $result = $this->service()->syncAll();

        $this->assertSame(['recorded' => 0, 'failed' => 0, 'total' => 0], $result);
        $this->assertSame(0, ContainerStatsHistory::count());
    }

    public function test_missing_precpu_stats_yields_a_null_cpu_percent_instead_of_a_bogus_value(): void
    {
        Http::fake([
            '*/containers/json*' => Http::response([
                ['Id' => 'x1', 'Names' => ['/boss-app']],
            ], 200),
            '*/containers/x1/stats*' => Http::response([
                'cpu_stats' => ['cpu_usage' => ['total_usage' => 100], 'system_cpu_usage' => 100, 'online_cpus' => 6],
                'precpu_stats' => ['cpu_usage' => ['total_usage' => 0]],
                'memory_stats' => ['usage' => 1000],
                'networks' => [],
            ], 200),
        ]);

        $this->service()->syncAll();

        $this->assertNull(ContainerStatsHistory::sole()->cpu_percent);
    }

    // v0.8.3 — explicit VPN/LibreNMS/BOSS App Core/Lainnya grouping.

    public function test_group_for_recognizes_every_real_vpn_container(): void
    {
        foreach (['openvpn', 'openvpn-node2', 'openvpn-node3', 'wireguard', 'wireguard-node2', 'wireguard-node3', 'l2tp'] as $name) {
            $this->assertSame('VPN', ContainerStatsService::groupFor($name), "expected {$name} to be VPN");
        }
    }

    public function test_group_for_recognizes_every_real_librenms_container(): void
    {
        foreach (['librenms', 'librenms-db', 'librenms-dispatcher', 'librenms-redis'] as $name) {
            $this->assertSame('LibreNMS', ContainerStatsService::groupFor($name), "expected {$name} to be LibreNMS");
        }
    }

    public function test_group_for_recognizes_every_real_boss_app_core_container(): void
    {
        foreach (['boss-app', 'boss-worker', 'boss-nginx', 'boss-postgresql', 'boss-redis', 'boss-scheduler', 'boss-whatsapp-worker'] as $name) {
            $this->assertSame('BOSS App Core', ContainerStatsService::groupFor($name), "expected {$name} to be BOSS App Core");
        }
    }

    public function test_group_for_falls_back_to_lainnya_for_an_unrecognized_container(): void
    {
        foreach (['mongo', 'whatsapp-gateway', 'freeradius', 'freeradius-db', 'genieacs-cwmp', 'genieacs-nbi', 'genieacs-fs', 'genieacs-ui', 'docker-stats-proxy', 'a-brand-new-future-container'] as $name) {
            $this->assertSame('Lainnya', ContainerStatsService::groupFor($name), "expected {$name} to be Lainnya");
        }
    }
}
