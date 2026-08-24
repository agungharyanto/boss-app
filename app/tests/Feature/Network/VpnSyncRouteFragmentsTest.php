<?php

namespace Tests\Feature\Network;

use App\Enums\VpnAccountStatus;
use App\Models\Nas;
use App\Models\OltDevice;
use App\Models\VpnAccount;
use App\Models\VpnWireguardNasBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * v0.8.1 fragment+reconcile (replaces the OSPF experiment — see CLAUDE.md).
 * v0.8.4 — "current node" now comes from `wg-status-*` files written by
 * each WireGuard node's own `wg show wg0 dump` (see VpnSyncRouteFragments's
 * own docblock), not from a RouterOsGateway call — these tests write fake
 * `wg-status-*` files directly instead of binding a fake gateway.
 */
class VpnSyncRouteFragmentsTest extends TestCase
{
    use RefreshDatabase;

    private string $wgDataDir;

    private string $routesDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wgDataDir = sys_get_temp_dir().'/vpn-wg-data-test-'.uniqid();
        $this->routesDir = "{$this->wgDataDir}/routes";

        File::makeDirectory($this->wgDataDir, 0777, true);

        config([
            'services.vpn.routes_dir' => $this->routesDir,
            'services.vpn.wg_peers_dir' => "{$this->wgDataDir}/peers",
            'services.vpn.wireguard_node_ips' => [
                51820 => '172.28.0.11',
                51821 => '172.28.0.4',
                51822 => '172.28.0.5',
            ],
            'services.vpn.olt_management_subnet' => '10.168.100.0/24',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->wgDataDir);

        parent::tearDown();
    }

    /**
     * Writes a fake `wg-status-{hostname}` file matching real `wg show wg0
     * dump` tab-delimited output — line 1 is the interface line (own
     * listen-port in column 3), each entry in $peers becomes one peer line
     * with the given public key and handshake timestamp (column 5).
     *
     * @param  array<int, array{publicKey: string, handshakeAt: int}>  $peers
     */
    private function writeNodeStatus(string $hostname, int $listenPort, array $peers): void
    {
        $lines = ['REDACTED'."\t".'node-own-pubkey'."\t".$listenPort."\t".'off'];

        foreach ($peers as $peer) {
            $lines[] = implode("\t", [
                $peer['publicKey'],
                '(none)',
                '203.0.113.1:51820',
                '10.0.0.0/24',
                $peer['handshakeAt'],
                '1024',
                '2048',
                '25',
            ]);
        }

        File::put("{$this->wgDataDir}/wg-status-{$hostname}", implode("\n", $lines)."\n");
    }

    private function fragmentPath(Nas $nas): string
    {
        return "{$this->routesDir}/nas-{$nas->id}.conf";
    }

    public function test_writes_router_and_tr069_subnet_lines_for_an_active_nas(): void
    {
        $nas = Nas::factory()->provisioned()->create(['tr069_management_subnet' => '10.1.0.0/20']);
        $account = VpnAccount::factory()->create([
            'nas_id' => $nas->id, 'protocol' => 'wireguard', 'status' => VpnAccountStatus::Active,
            'username' => "nas-{$nas->id}", 'public_key' => 'pubkey-nas-1',
        ]);
        VpnWireguardNasBlock::factory()->create([
            'nas_id' => $nas->id, 'vpn_server_id' => $account->vpn_server_id,
            'gateway_ip' => '172.23.195.1', 'router_ip' => '172.23.195.2',
        ]);
        $this->writeNodeStatus('wireguard-node2', 51821, [
            ['publicKey' => 'pubkey-nas-1', 'handshakeAt' => time() - 30],
        ]);

        $this->artisan('vpn:sync-route-fragments')->assertSuccessful();

        $content = File::get($this->fragmentPath($nas));
        $this->assertStringContainsString('172.23.195.2/32 via 172.28.0.4', $content);
        // v0.8.4 amendment — a route to gateway_ip was briefly asserted
        // here too; removed along with the production code that wrote it
        // (see VpnSyncRouteFragments's own docblock — gateway_ip is never
        // a real communication endpoint from FreeRADIUS's side).
        $this->assertStringNotContainsString('172.23.195.1/32', $content);
        $this->assertStringContainsString('10.1.0.0/20 via 172.28.0.4', $content);
        // No OLT registered for this NAS — must NOT appear.
        $this->assertStringNotContainsString('10.168.100.0/24', $content);
    }

    public function test_includes_olt_subnet_only_when_nas_has_a_registered_olt(): void
    {
        $nas = Nas::factory()->provisioned()->create(['tr069_management_subnet' => null]);
        $account = VpnAccount::factory()->create([
            'nas_id' => $nas->id, 'protocol' => 'wireguard', 'status' => VpnAccountStatus::Active,
            'username' => "nas-{$nas->id}", 'public_key' => 'pubkey-nas-3',
        ]);
        VpnWireguardNasBlock::factory()->create([
            'nas_id' => $nas->id, 'vpn_server_id' => $account->vpn_server_id,
            'gateway_ip' => '172.23.195.5', 'router_ip' => '172.23.195.6',
        ]);
        OltDevice::factory()->create(['nas_id' => $nas->id]);
        $this->writeNodeStatus('wireguard-node3', 51822, [
            ['publicKey' => 'pubkey-nas-3', 'handshakeAt' => time() - 5],
        ]);

        $this->artisan('vpn:sync-route-fragments')->assertSuccessful();

        $content = File::get($this->fragmentPath($nas));
        $this->assertStringContainsString('10.168.100.0/24 via 172.28.0.5', $content);
    }

    public function test_undetectable_current_node_removes_any_existing_fragment_instead_of_leaving_it_stale(): void
    {
        $nas = Nas::factory()->provisioned()->create();
        $account = VpnAccount::factory()->create([
            'nas_id' => $nas->id, 'protocol' => 'wireguard', 'status' => VpnAccountStatus::Active,
            'username' => "nas-{$nas->id}", 'public_key' => 'pubkey-undetectable',
        ]);
        VpnWireguardNasBlock::factory()->create([
            'nas_id' => $nas->id, 'vpn_server_id' => $account->vpn_server_id,
        ]);
        File::makeDirectory($this->routesDir, 0777, true);
        File::put($this->fragmentPath($nas), "172.23.195.2/32 via 172.28.0.5\n");

        // No wg-status-* files at all — matches "no node has ever
        // handshaked with this public key", same as a router-unreachable
        // condition under the old mechanism.

        $this->artisan('vpn:sync-route-fragments')->assertSuccessful();

        $this->assertFileDoesNotExist($this->fragmentPath($nas));
    }

    public function test_stale_handshake_beyond_threshold_is_treated_as_undetectable(): void
    {
        $nas = Nas::factory()->provisioned()->create();
        $account = VpnAccount::factory()->create([
            'nas_id' => $nas->id, 'protocol' => 'wireguard', 'status' => VpnAccountStatus::Active,
            'username' => "nas-{$nas->id}", 'public_key' => 'pubkey-stale',
        ]);
        VpnWireguardNasBlock::factory()->create([
            'nas_id' => $nas->id, 'vpn_server_id' => $account->vpn_server_id,
            'gateway_ip' => '172.23.195.1', 'router_ip' => '172.23.195.2',
        ]);
        // 301s old — 1s past the 300s staleness threshold.
        $this->writeNodeStatus('wireguard', 51820, [
            ['publicKey' => 'pubkey-stale', 'handshakeAt' => time() - 301],
        ]);

        $this->artisan('vpn:sync-route-fragments')->assertSuccessful();

        $this->assertFileDoesNotExist($this->fragmentPath($nas));
    }

    public function test_revoked_account_gets_its_fragment_removed_even_if_it_still_exists_on_disk(): void
    {
        $nas = Nas::factory()->provisioned()->create();
        VpnAccount::factory()->create([
            'nas_id' => $nas->id, 'protocol' => 'wireguard', 'status' => VpnAccountStatus::Revoked,
            'username' => "nas-{$nas->id}", 'public_key' => 'pubkey-revoked',
        ]);
        File::makeDirectory($this->routesDir, 0777, true);
        File::put($this->fragmentPath($nas), "172.23.195.2/32 via 172.28.0.5\n");

        $this->writeNodeStatus('wireguard', 51820, [
            ['publicKey' => 'pubkey-revoked', 'handshakeAt' => time() - 5],
        ]);

        $this->artisan('vpn:sync-route-fragments')->assertSuccessful();

        $this->assertFileDoesNotExist($this->fragmentPath($nas));
    }

    public function test_two_different_nas_on_two_different_current_nodes_each_get_their_own_correct_fragment(): void
    {
        $nasA = Nas::factory()->provisioned()->create();
        $accountA = VpnAccount::factory()->create([
            'nas_id' => $nasA->id, 'protocol' => 'wireguard', 'status' => VpnAccountStatus::Active,
            'username' => "nas-{$nasA->id}", 'public_key' => 'pubkey-a',
        ]);
        VpnWireguardNasBlock::factory()->create([
            'nas_id' => $nasA->id, 'vpn_server_id' => $accountA->vpn_server_id,
            'gateway_ip' => '172.23.195.1', 'router_ip' => '172.23.195.2',
        ]);

        $nasB = Nas::factory()->provisioned()->create();
        $accountB = VpnAccount::factory()->create([
            'nas_id' => $nasB->id, 'protocol' => 'wireguard', 'status' => VpnAccountStatus::Active,
            'username' => "nas-{$nasB->id}", 'public_key' => 'pubkey-b',
        ]);
        VpnWireguardNasBlock::factory()->create([
            'nas_id' => $nasB->id, 'vpn_server_id' => $accountB->vpn_server_id,
            'gateway_ip' => '172.23.195.5', 'router_ip' => '172.23.195.6',
        ]);

        // NAS A is currently on node1 (51820), NAS B on node3 (51822) —
        // each node's own status file only knows about its own live peer.
        $this->writeNodeStatus('wireguard', 51820, [
            ['publicKey' => 'pubkey-a', 'handshakeAt' => time() - 10],
        ]);
        $this->writeNodeStatus('wireguard-node3', 51822, [
            ['publicKey' => 'pubkey-b', 'handshakeAt' => time() - 10],
        ]);

        $this->artisan('vpn:sync-route-fragments')->assertSuccessful();

        $contentA = File::get($this->fragmentPath($nasA));
        $contentB = File::get($this->fragmentPath($nasB));
        $this->assertStringContainsString('172.23.195.2/32 via 172.28.0.11', $contentA);
        $this->assertStringContainsString('172.23.195.6/32 via 172.28.0.5', $contentB);
    }

    public function test_picks_the_node_with_the_freshest_handshake_when_the_same_public_key_appears_on_more_than_one_node(): void
    {
        // Every NAS peer is provisioned onto all 3 nodes at all times —
        // only the node with the genuinely freshest handshake should win,
        // never just "whichever status file happened to be read last".
        $nas = Nas::factory()->provisioned()->create();
        $account = VpnAccount::factory()->create([
            'nas_id' => $nas->id, 'protocol' => 'wireguard', 'status' => VpnAccountStatus::Active,
            'username' => "nas-{$nas->id}", 'public_key' => 'pubkey-multi',
        ]);
        VpnWireguardNasBlock::factory()->create([
            'nas_id' => $nas->id, 'vpn_server_id' => $account->vpn_server_id,
            'gateway_ip' => '172.23.195.1', 'router_ip' => '172.23.195.2',
        ]);

        // Stale on node1 (long-dead handshake from before an auto-switch).
        $this->writeNodeStatus('wireguard', 51820, [
            ['publicKey' => 'pubkey-multi', 'handshakeAt' => time() - 5000],
        ]);
        // Genuinely live on node2 — this one should win.
        $this->writeNodeStatus('wireguard-node2', 51821, [
            ['publicKey' => 'pubkey-multi', 'handshakeAt' => time() - 3],
        ]);

        $this->artisan('vpn:sync-route-fragments')->assertSuccessful();

        $content = File::get($this->fragmentPath($nas));
        $this->assertStringContainsString('172.23.195.2/32 via 172.28.0.4', $content);
    }
}
