<?php

namespace Tests\Feature\Network;

use App\Enums\VpnAccountStatus;
use App\Enums\VpnProtocol;
use App\Models\Nas;
use App\Models\Tenant;
use App\Models\VpnServer;
use App\Services\Network\VpnProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class VpnProvisioningMultiProtocolTest extends TestCase
{
    use RefreshDatabase;

    private string $wgPeersDir;

    private string $l2tpSecretsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wgPeersDir = sys_get_temp_dir().'/vpn-wg-peers-test-'.uniqid();
        $this->l2tpSecretsDir = sys_get_temp_dir().'/vpn-l2tp-secrets-test-'.uniqid();

        config([
            'services.vpn.wg_peers_dir' => $this->wgPeersDir,
            'services.vpn.l2tp_secrets_dir' => $this->l2tpSecretsDir,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->wgPeersDir);
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
