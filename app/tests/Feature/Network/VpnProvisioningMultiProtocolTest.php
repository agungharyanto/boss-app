<?php

namespace Tests\Feature\Network;

use App\Enums\VpnAccountStatus;
use App\Enums\VpnProtocol;
use App\Models\Nas;
use App\Models\Tenant;
use App\Models\VpnServer;
use App\Models\VpnWireguardNasBlock;
use App\Services\Network\VpnProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class VpnProvisioningMultiProtocolTest extends TestCase
{
    use RefreshDatabase;

    private string $wgPeersDir;

    private string $wgAddressesDir;

    private string $l2tpSecretsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wgPeersDir = sys_get_temp_dir().'/vpn-wg-peers-test-'.uniqid();
        // v0.8.1 — real incident caught while adding this: without this
        // isolation, VpnProvisioningService::issueWireGuardCredentials()'s
        // new address-fragment write silently escaped the test sandbox
        // and landed in the REAL production /vpn-wg-data/addresses volume
        // (config('services.vpn.wg_addresses_dir') falling back to its
        // real default) — confirmed and cleaned up (a stray "nas-1.conf"
        // with synthetic test data, zero live effect since the currently-
        // deployed entrypoint.sh doesn't read that directory at all yet,
        // but still a real test-hygiene bug, not hypothetical).
        $this->wgAddressesDir = sys_get_temp_dir().'/vpn-wg-addresses-test-'.uniqid();
        $this->l2tpSecretsDir = sys_get_temp_dir().'/vpn-l2tp-secrets-test-'.uniqid();

        config([
            'services.vpn.wg_peers_dir' => $this->wgPeersDir,
            'services.vpn.wg_addresses_dir' => $this->wgAddressesDir,
            'services.vpn.l2tp_secrets_dir' => $this->l2tpSecretsDir,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->wgPeersDir);
        File::deleteDirectory($this->wgAddressesDir);
        File::deleteDirectory($this->l2tpSecretsDir);

        parent::tearDown();
    }

    private function serverFor(VpnProtocol $protocol, string $cidr = '172.23.200.0/29'): VpnServer
    {
        $server = VpnServer::factory()->create(['protocol' => $protocol, 'subnet_cidr' => $cidr]);
        $server->provisionIpPool();

        return $server;
    }

    // ------------------------------------------------------------------
    // WireGuard
    // ------------------------------------------------------------------

    public function test_provision_wireguard_generates_keypair_writes_peer_file_and_returns_private_key_once(): void
    {
        Process::fake([
            '*wg*genkey*' => Process::result(output: "client-private-key-value\n"),
            '*wg*pubkey*' => Process::result(output: "client-public-key-value\n"),
        ]);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $this->serverFor(VpnProtocol::WireGuard);

        $account = app(VpnProvisioningService::class)->provision($nas, VpnProtocol::WireGuard);

        $this->assertSame(VpnProtocol::WireGuard, $account->protocol);
        $this->assertSame('client-public-key-value', $account->public_key);
        $this->assertSame('client-private-key-value', $account->wireguardPrivateKey);

        $peerFile = $this->wgPeersDir.'/'.$account->username.'.conf';
        $this->assertFileExists($peerFile);
        $contents = File::get($peerFile);
        $this->assertStringContainsString('PublicKey = client-public-key-value', $contents);
        $this->assertStringContainsString("AllowedIPs = {$account->internal_ip}/32", $contents);

        // The private key must NEVER be persisted — re-fetching from the DB
        // gives a fresh model instance with the transient property unset.
        $reloaded = $account->fresh();
        $this->assertNull($reloaded->wireguardPrivateKey);
    }

    /**
     * Real P0 bug found and fixed while verifying OLT SNMP end-to-end for
     * real: docker/wireguard/entrypoint.sh's `ip route`/iptables additions
     * for OLT_MANAGEMENT_SUBNET are NOT sufficient on their own —
     * AllowedIPs is WireGuard's own cryptokey-routing filter, checked
     * BEFORE the kernel even attempts to send a packet (confirmed for
     * real: `ping -I wg0 <olt-ip>` failed outright with "sendto: Required
     * key not available" until this fix). No `nas.olt_management_subnet`
     * column exists — same single-global-subnet limitation as everywhere
     * else OLT_MANAGEMENT_SUBNET is read, so this widens every WireGuard
     * account's AllowedIPs identically, not per-NAS.
     */
    public function test_provision_wireguard_widens_allowed_ips_to_the_olt_management_subnet_when_configured(): void
    {
        config(['services.vpn.olt_management_subnet' => '10.168.100.0/24']);

        Process::fake([
            '*wg*genkey*' => Process::result(output: "client-private-key-value\n"),
            '*wg*pubkey*' => Process::result(output: "client-public-key-value\n"),
        ]);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $this->serverFor(VpnProtocol::WireGuard);

        $account = app(VpnProvisioningService::class)->provision($nas, VpnProtocol::WireGuard);

        $contents = File::get($this->wgPeersDir.'/'.$account->username.'.conf');
        $this->assertStringContainsString(
            "AllowedIPs = {$account->internal_ip}/32, 10.168.100.0/24",
            $contents
        );
    }

    public function test_provision_wireguard_omits_olt_management_subnet_from_allowed_ips_when_not_configured(): void
    {
        config(['services.vpn.olt_management_subnet' => null]);

        Process::fake([
            '*wg*genkey*' => Process::result(output: "client-private-key-value\n"),
            '*wg*pubkey*' => Process::result(output: "client-public-key-value\n"),
        ]);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $this->serverFor(VpnProtocol::WireGuard);

        $account = app(VpnProvisioningService::class)->provision($nas, VpnProtocol::WireGuard);

        $contents = File::get($this->wgPeersDir.'/'.$account->username.'.conf');
        $this->assertStringNotContainsString('10.168.100.0/24', $contents);
    }

    /**
     * Both widenings (tr069_management_subnet AND OLT_MANAGEMENT_SUBNET)
     * must be able to coexist on the SAME peer file — this is exactly
     * test-x86-bajastu's real configuration (both subnets set at once).
     */
    public function test_provision_wireguard_combines_tr069_and_olt_subnet_widening_when_both_are_set(): void
    {
        config(['services.vpn.olt_management_subnet' => '10.168.100.0/24']);

        Process::fake([
            '*wg*genkey*' => Process::result(output: "client-private-key-value\n"),
            '*wg*pubkey*' => Process::result(output: "client-public-key-value\n"),
        ]);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id, 'tr069_management_subnet' => '10.1.0.0/20']);
        $this->serverFor(VpnProtocol::WireGuard);

        $account = app(VpnProvisioningService::class)->provision($nas, VpnProtocol::WireGuard);

        $contents = File::get($this->wgPeersDir.'/'.$account->username.'.conf');
        $this->assertStringContainsString(
            "AllowedIPs = {$account->internal_ip}/32, 10.1.0.0/20, 10.168.100.0/24",
            $contents
        );
    }

    /**
     * v0.8.1 — WireGuard no longer consumes vpn_ip_pool at all (OpenVPN/
     * L2TP still do, unaffected — see the sibling tests below). internal_ip
     * comes from a dedicated VpnWireguardNasBlock instead.
     */
    public function test_provision_wireguard_uses_a_dedicated_block_not_the_shared_vpn_ip_pool(): void
    {
        Process::fake([
            '*wg*genkey*' => Process::result(output: "priv\n"),
            '*wg*pubkey*' => Process::result(output: "pub\n"),
        ]);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $this->serverFor(VpnProtocol::WireGuard, '172.23.195.0/24');

        $account = app(VpnProvisioningService::class)->provision($nas, VpnProtocol::WireGuard);

        $block = VpnWireguardNasBlock::where('nas_id', $nas->id)->firstOrFail();
        $this->assertSame($block->router_ip, $account->internal_ip);
        $this->assertSame('172.23.195.2', $account->internal_ip);
        $this->assertSame('172.23.195.1', $block->gateway_ip);
        $this->assertSame(0, $block->block_index);

        // No vpn_ip_pool row was ever consumed for this WireGuard account —
        // serverFor() below still provisions the pool (shared helper, used
        // by every protocol's tests in this file), so the assertion here
        // is specifically "no row is ASSIGNED to this account", not "the
        // pool table is empty" (which would just be testing serverFor()
        // itself, not the actual claim).
        $this->assertDatabaseMissing('vpn_ip_pool', ['vpn_account_id' => $account->id]);
    }

    /**
     * Sticky: the SAME NAS revoking and re-provisioning its WireGuard
     * account (exactly what "Cabut & Generate Ulang" does in production,
     * repeatedly, per this whole sprint's own history) must land on the
     * SAME block every time — a fresh keypair, but the same gateway/router
     * IP pair, so the router-side script never needs its /ip address line
     * to change across routine regenerations.
     */
    public function test_provision_wireguard_reuses_the_same_block_across_revoke_and_reprovision(): void
    {
        Process::fake([
            '*wg*genkey*' => Process::result(output: "priv\n"),
            '*wg*pubkey*' => Process::result(output: "pub\n"),
        ]);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $this->serverFor(VpnProtocol::WireGuard, '172.23.195.0/24');

        $provisioning = app(VpnProvisioningService::class);

        $first = $provisioning->provision($nas, VpnProtocol::WireGuard);
        $provisioning->revoke($first);
        $second = $provisioning->provision($nas, VpnProtocol::WireGuard);
        $provisioning->revoke($second);
        $third = $provisioning->provision($nas, VpnProtocol::WireGuard);

        $this->assertSame($first->internal_ip, $second->internal_ip);
        $this->assertSame($second->internal_ip, $third->internal_ip);
        $this->assertDatabaseCount('vpn_wireguard_nas_blocks', 1);
    }

    /**
     * FCFS across different NAS — whichever NAS asks first gets block #0,
     * the next distinct NAS gets block #1, and so on, with zero address
     * overlap between them.
     */
    public function test_provision_wireguard_allocates_sequential_non_overlapping_blocks_for_different_nas(): void
    {
        Process::fake([
            '*wg*genkey*' => Process::result(output: "priv\n"),
            '*wg*pubkey*' => Process::result(output: "pub\n"),
        ]);
        $tenant = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasC = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $this->serverFor(VpnProtocol::WireGuard, '172.23.195.0/24');

        $provisioning = app(VpnProvisioningService::class);

        $accountB = $provisioning->provision($nasB, VpnProtocol::WireGuard);
        $accountA = $provisioning->provision($nasA, VpnProtocol::WireGuard);
        $accountC = $provisioning->provision($nasC, VpnProtocol::WireGuard);

        // Allocation order is FCFS by WHEN provision() was called, not by
        // nas.id — $nasB asked first here despite having the lowest id.
        $this->assertSame(0, VpnWireguardNasBlock::where('nas_id', $nasB->id)->value('block_index'));
        $this->assertSame(1, VpnWireguardNasBlock::where('nas_id', $nasA->id)->value('block_index'));
        $this->assertSame(2, VpnWireguardNasBlock::where('nas_id', $nasC->id)->value('block_index'));

        $ips = [$accountA->internal_ip, $accountB->internal_ip, $accountC->internal_ip];
        $this->assertSame($ips, array_unique($ips), 'internal_ip must never overlap between different NAS.');

        $gateways = VpnWireguardNasBlock::pluck('gateway_ip')->all();
        $this->assertSame($gateways, array_unique($gateways), 'gateway_ip must never overlap between different NAS.');
    }

    public function test_revoke_wireguard_deletes_the_peer_file(): void
    {
        Process::fake([
            '*wg*genkey*' => Process::result(output: "priv\n"),
            '*wg*pubkey*' => Process::result(output: "pub\n"),
        ]);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $this->serverFor(VpnProtocol::WireGuard);

        $account = app(VpnProvisioningService::class)->provision($nas, VpnProtocol::WireGuard);
        $peerFile = $this->wgPeersDir.'/'.$account->username.'.conf';
        $this->assertFileExists($peerFile);

        $revoked = app(VpnProvisioningService::class)->revoke($account);

        $this->assertSame(VpnAccountStatus::Revoked, $revoked->status);
        $this->assertFileDoesNotExist($peerFile);
    }

    // ------------------------------------------------------------------
    // L2TP/IPsec
    // ------------------------------------------------------------------

    public function test_provision_l2tp_generates_password_and_writes_chap_secrets(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $this->serverFor(VpnProtocol::L2tpIpsec);

        $account = app(VpnProvisioningService::class)->provision($nas, VpnProtocol::L2tpIpsec);

        $this->assertSame(VpnProtocol::L2tpIpsec, $account->protocol);
        $this->assertNotNull($account->password);

        $secretsFile = $this->l2tpSecretsDir.'/chap-secrets';
        $this->assertFileExists($secretsFile);
        $this->assertStringContainsString("{$account->username} l2tpd {$account->password} *", File::get($secretsFile));
    }

    public function test_provision_l2tp_chap_secrets_lists_every_active_account_not_just_the_latest(): void
    {
        $tenant = Tenant::factory()->create();
        $server = $this->serverFor(VpnProtocol::L2tpIpsec, '172.23.200.0/28');
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);

        $service = app(VpnProvisioningService::class);
        $accountA = $service->provision($nasA, VpnProtocol::L2tpIpsec);
        $accountB = $service->provision($nasB, VpnProtocol::L2tpIpsec);

        $contents = File::get($this->l2tpSecretsDir.'/chap-secrets');
        $this->assertStringContainsString("{$accountA->username} l2tpd {$accountA->password} *", $contents);
        $this->assertStringContainsString("{$accountB->username} l2tpd {$accountB->password} *", $contents);
    }

    public function test_revoke_l2tp_removes_only_that_accounts_line_from_chap_secrets(): void
    {
        $tenant = Tenant::factory()->create();
        $this->serverFor(VpnProtocol::L2tpIpsec, '172.23.200.0/28');
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);

        $service = app(VpnProvisioningService::class);
        $accountA = $service->provision($nasA, VpnProtocol::L2tpIpsec);
        $accountB = $service->provision($nasB, VpnProtocol::L2tpIpsec);

        $service->revoke($accountA);

        $contents = File::get($this->l2tpSecretsDir.'/chap-secrets');
        $this->assertStringNotContainsString($accountA->username.' l2tpd', $contents);
        $this->assertStringContainsString("{$accountB->username} l2tpd {$accountB->password} *", $contents);
    }

    // ------------------------------------------------------------------
    // Cross-protocol
    // ------------------------------------------------------------------

    public function test_a_nas_can_have_simultaneous_active_accounts_on_different_protocols(): void
    {
        Process::fake([
            '*wg*genkey*' => Process::result(output: "priv\n"),
            '*wg*pubkey*' => Process::result(output: "pub\n"),
        ]);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $this->serverFor(VpnProtocol::WireGuard, '172.23.201.0/29');
        $this->serverFor(VpnProtocol::L2tpIpsec, '172.23.202.0/29');

        $service = app(VpnProvisioningService::class);
        $wgAccount = $service->provision($nas, VpnProtocol::WireGuard);
        $l2tpAccount = $service->provision($nas, VpnProtocol::L2tpIpsec);

        $this->assertNotSame($wgAccount->id, $l2tpAccount->id);
        $this->assertSame(VpnAccountStatus::Active, $wgAccount->fresh()->status);
        $this->assertSame(VpnAccountStatus::Active, $l2tpAccount->fresh()->status);
    }
}
