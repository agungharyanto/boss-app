<?php

namespace Tests\Feature\Network;

use App\Models\Nas;
use App\Models\Tenant;
use App\Models\VpnAccount;
use App\Models\VpnServer;
use App\Services\Network\Contracts\NasWireguardInspector;
use App\Services\Network\NasRadiusDiagnosticService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class NasRadiusDiagnosticServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $statusDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statusDir = sys_get_temp_dir().'/nas-diag-'.uniqid();
        File::makeDirectory($this->statusDir.'/peers', 0777, true);
        File::makeDirectory($this->statusDir.'/routes', 0777, true);
        config([
            'services.vpn.wg_peers_dir' => $this->statusDir.'/peers',
            'services.vpn.routes_dir' => $this->statusDir.'/routes',
            'services.vpn.freeradius_internal_ip' => '172.28.0.225',
            'services.vpn.wireguard_node_ips' => [51820 => '172.28.0.11', 51821 => '172.28.0.4', 51822 => '172.28.0.5'],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->statusDir);
        parent::tearDown();
    }

    private function writeWgStatus(string $node, string $peerPubkey, int $handshakeTs): void
    {
        // line 1 = interface (redacted key, own pubkey, listen-port, fwmark)
        // line 2+ = peers (pubkey, psk, endpoint, allowed-ips, latest-handshake, rx, tx, keepalive)
        $content = "REDACTED\tOWNPUB{$node}\t51820\toff\n"
            ."{$peerPubkey}\t(none)\t1.2.3.4:5678\t172.23.195.6/32\t{$handshakeTs}\t1000\t2000\toff\n";
        File::put("{$this->statusDir}/wg-status-{$node}", $content);
    }

    private function writeHeartbeat(string $node, int $ts): void
    {
        File::put("{$this->statusDir}/heartbeat-{$node}", (string) $ts);
    }

    private function nasWithActiveWgAccount(string $pubkey = 'PEERPUBKEY123'): Nas
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id, 'name' => 'diag-nas']);
        $server = VpnServer::factory()->create(['protocol' => 'wireguard']);
        VpnAccount::factory()->create([
            'nas_id' => $nas->id, 'vpn_server_id' => $server->id, 'protocol' => 'wireguard',
            'status' => 'active', 'public_key' => $pubkey,
        ]);

        return $nas;
    }

    private function fakeInspector(?array $peerStatus, ?bool $ping, bool $retrigger = true): NasWireguardInspector
    {
        return new class($peerStatus, $ping, $retrigger) implements NasWireguardInspector
        {
            public function __construct(private ?array $peerStatus, private ?bool $ping, private bool $retrigger) {}

            public function peerStatus(Nas $nas): ?array
            {
                return $this->peerStatus;
            }

            public function pingFromRouter(Nas $nas, string $targetIp, int $count = 3): ?bool
            {
                return $this->ping;
            }

            public function retriggerPeerHandshake(Nas $nas): bool
            {
                return $this->retrigger;
            }
        };
    }

    public function test_all_green_when_handshake_fresh_node_alive_ping_ok_router_ok(): void
    {
        $nas = $this->nasWithActiveWgAccount('PK-FRESH');
        $this->writeWgStatus('vpn-node-1', 'PK-FRESH', time() - 10);
        $this->writeHeartbeat('vpn-node-1', time() - 5);

        $svc = new NasRadiusDiagnosticService($this->fakeInspector(
            ['interface_running' => true, 'peer_found' => true, 'last_handshake' => '11s', 'rx' => 500, 'tx' => 900, 'endpoint' => '1.2.3.4:5678'],
            true,
        ));

        $r = $svc->run($nas);

        $this->assertSame('ok', $r['overall']);
        $this->assertSame('ok', collect($r['steps'])->firstWhere('key', 'tunnel')['status']);
        $this->assertSame('ok', collect($r['steps'])->firstWhere('key', 'freeradius')['status']);
        $this->assertSame('ok', collect($r['steps'])->firstWhere('key', 'router_wg')['status']);
        $this->assertFalse($r['self_solve_available']);
    }

    public function test_dead_node_reports_fail_and_a_suggestion_and_no_self_solve(): void
    {
        $nas = $this->nasWithActiveWgAccount('PK-DEADNODE');
        $this->writeWgStatus('vpn-node-1', 'PK-DEADNODE', time() - 4000); // last handshake before node died
        $this->writeHeartbeat('vpn-node-1', time() - 3600);              // heartbeat 1h stale => node down

        $svc = new NasRadiusDiagnosticService($this->fakeInspector(null, null));
        $r = $svc->run($nas);

        $this->assertSame('down', $r['overall']);
        $this->assertSame('fail', collect($r['steps'])->firstWhere('key', 'tunnel')['status']);
        $this->assertFalse($r['self_solve_available'], 'retrigger cannot fix a dead node container');
        $this->assertStringContainsStringIgnoringCase('node wireguard', json_encode($r['suggestions']));
    }

    public function test_stale_handshake_but_live_node_offers_self_solve(): void
    {
        $nas = $this->nasWithActiveWgAccount('PK-STALE');
        $this->writeWgStatus('vpn-node-1', 'PK-STALE', time() - 800); // stale (>600s)
        $this->writeHeartbeat('vpn-node-1', time() - 5);              // node alive

        $svc = new NasRadiusDiagnosticService($this->fakeInspector(
            ['interface_running' => true, 'peer_found' => true, 'last_handshake' => 'never', 'rx' => 0, 'tx' => 3000, 'endpoint' => '1.2.3.4:5678'],
            false,
        ));
        $r = $svc->run($nas);

        $this->assertSame('fail', collect($r['steps'])->firstWhere('key', 'tunnel')['status']);
        $this->assertSame('fail', collect($r['steps'])->firstWhere('key', 'freeradius')['status']);
        $this->assertSame('fail', collect($r['steps'])->firstWhere('key', 'router_wg')['status']);
        $this->assertTrue($r['self_solve_available']);
    }

    public function test_no_active_account_fails_step_one_with_a_suggestion(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        $svc = new NasRadiusDiagnosticService($this->fakeInspector(null, null));
        $r = $svc->run($nas);

        $this->assertSame('fail', collect($r['steps'])->firstWhere('key', 'tunnel')['status']);
        $this->assertStringContainsStringIgnoringCase('akun wireguard', json_encode($r['suggestions']));
    }

    public function test_unreachable_router_api_marks_steps_2_and_3_skipped_not_failed(): void
    {
        $nas = $this->nasWithActiveWgAccount('PK-APIDOWN');
        $this->writeWgStatus('vpn-node-1', 'PK-APIDOWN', time() - 15);
        $this->writeHeartbeat('vpn-node-1', time() - 5);

        $svc = new NasRadiusDiagnosticService($this->fakeInspector(null, null)); // API unreachable
        $r = $svc->run($nas);

        $this->assertSame('ok', collect($r['steps'])->firstWhere('key', 'tunnel')['status']);
        $this->assertSame('skip', collect($r['steps'])->firstWhere('key', 'freeradius')['status']);
        $this->assertSame('skip', collect($r['steps'])->firstWhere('key', 'router_wg')['status']);
        $this->assertSame('degraded', $r['overall']);
    }

    public function test_self_solve_retriggers_handshake_and_syncs_route_fragments(): void
    {
        $nas = $this->nasWithActiveWgAccount('PK-SS');

        $svc = new NasRadiusDiagnosticService($this->fakeInspector(null, null, retrigger: true));
        $out = $svc->selfSolve($nas);

        $this->assertTrue($out['retriggered']);
        $this->assertTrue($out['route_synced']);
    }
}
