<?php

namespace Tests\Feature\Network;

use App\Enums\VpnServerStatus;
use App\Models\VpnServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * v0.6.4 — a real bug was found running this for real: boss-scheduler (the
 * container that actually executes routes/console.php's scheduled
 * ->everyMinute() call) didn't mount the same vpn_pki/vpn_wg_data volumes
 * as boss-app, so every heartbeat silently read as "missing" and every
 * node flapped to Offline regardless of real status — fixed by adding
 * those volume mounts to boss-scheduler in docker-compose.yml. These tests
 * only cover the command's own logic (heartbeat freshness -> status), not
 * that docker-compose wiring — that class of bug isn't something a
 * sqlite-backed feature test can catch, only running the real containers
 * can (which is how it was actually found).
 */
class VpnCheckNodeHealthTest extends TestCase
{
    use RefreshDatabase;

    private string $pkiDir;

    private string $wgPeersDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pkiDir = sys_get_temp_dir().'/vpn-health-pki-'.uniqid();
        $this->wgPeersDir = sys_get_temp_dir().'/vpn-health-wg-'.uniqid().'/peers';
        File::makeDirectory($this->pkiDir, 0777, true);
        File::makeDirectory($this->wgPeersDir, 0777, true);

        config([
            'services.vpn.pki_dir' => $this->pkiDir,
            'services.vpn.wg_peers_dir' => $this->wgPeersDir,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->pkiDir));
        File::deleteDirectory(dirname($this->wgPeersDir));

        parent::tearDown();
    }

    private function writeHeartbeat(string $dir, string $hostname, int $secondsAgo): void
    {
        File::put(dirname($dir)."/heartbeat-{$hostname}", (string) (time() - $secondsAgo));
    }

    public function test_fresh_heartbeat_marks_a_previously_offline_node_online(): void
    {
        $server = VpnServer::factory()->create([
            'hostname' => 'vpn-node-1', 'protocol' => 'openvpn', 'status' => VpnServerStatus::Offline,
        ]);
        $this->writeHeartbeat($this->pkiDir, 'vpn-node-1', secondsAgo: 2);

        $this->artisan('vpn:check-node-health')->assertSuccessful();

        $this->assertSame(VpnServerStatus::Online, $server->fresh()->status);
    }

    public function test_stale_heartbeat_marks_a_previously_online_node_offline(): void
    {
        $server = VpnServer::factory()->create([
            'hostname' => 'vpn-node-1', 'protocol' => 'wireguard', 'status' => VpnServerStatus::Online,
        ]);
        // Older than the 30s stale threshold — simulates a wedged/crashed
        // reconcile loop that stopped writing heartbeats.
        $this->writeHeartbeat($this->wgPeersDir, 'vpn-node-1', secondsAgo: 90);

        $this->artisan('vpn:check-node-health')->assertSuccessful();

        $this->assertSame(VpnServerStatus::Offline, $server->fresh()->status);
    }

    public function test_missing_heartbeat_file_is_treated_as_offline(): void
    {
        $server = VpnServer::factory()->create([
            'hostname' => 'vpn-node-never-booted', 'protocol' => 'openvpn', 'status' => VpnServerStatus::Online,
        ]);
        // Deliberately no writeHeartbeat() call at all.

        $this->artisan('vpn:check-node-health')->assertSuccessful();

        $this->assertSame(VpnServerStatus::Offline, $server->fresh()->status);
    }

    public function test_alive_node_at_full_capacity_is_marked_full_not_online(): void
    {
        $server = VpnServer::factory()->create([
            'hostname' => 'vpn-node-1', 'protocol' => 'openvpn',
            'status' => VpnServerStatus::Online, 'current_clients' => 250, 'max_clients' => 250,
        ]);
        $this->writeHeartbeat($this->pkiDir, 'vpn-node-1', secondsAgo: 2);

        $this->artisan('vpn:check-node-health')->assertSuccessful();

        $this->assertSame(VpnServerStatus::Full, $server->fresh()->status);
    }

    public function test_l2tp_nodes_are_never_touched(): void
    {
        $server = VpnServer::factory()->create([
            'hostname' => 'vpn-node-1', 'protocol' => 'l2tp_ipsec', 'status' => VpnServerStatus::Online,
        ]);
        // No heartbeat mechanism exists for L2TP at all (known limitation,
        // not part of the v0.6.4 pool) — must not be flipped to Offline
        // just because it has no heartbeat file.

        $this->artisan('vpn:check-node-health')->assertSuccessful();

        $this->assertSame(VpnServerStatus::Online, $server->fresh()->status);
    }
}
