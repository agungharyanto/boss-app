<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\VpnScriptGenerator;
use App\Models\Nas;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VpnAccount;
use App\Models\VpnServer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

class VpnScriptGeneratorLivewireTest extends TestCase
{
    use RefreshDatabase;

    private string $pkiDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->pkiDir = sys_get_temp_dir().'/vpn-script-pki-test-'.uniqid();
        File::makeDirectory($this->pkiDir.'/issued', 0777, true);
        File::makeDirectory($this->pkiDir.'/private', 0777, true);
        File::put($this->pkiDir.'/ca.crt', 'CA-CONTENT');

        config([
            'services.vpn.pki_dir' => $this->pkiDir,
            'services.vpn.ccd_dir' => sys_get_temp_dir().'/vpn-script-ccd-test-'.uniqid(),
            'services.vpn.public_ip' => '45.123.142.242',
            'services.vpn.freeradius_internal_ip' => '172.28.0.10',
            // v0.8.1 — the reserved infra tunnel block (INFRA_TUNNEL_BLOCK_CIDR),
            // always fed into wireGuardScript() now regardless of what a
            // given NAS actually uses (see VpnScriptService's own docblock).
            'services.vpn.infra_block_cidr' => '172.28.0.224/27',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->pkiDir);

        parent::tearDown();
    }

    private function admin(Tenant $tenant): User
    {
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');

        return $admin;
    }

    public function test_non_admin_non_reseller_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($user)
            ->test(VpnScriptGenerator::class)
            ->assertForbidden();
    }

    public function test_generates_openvpn_script_by_reading_cert_files_from_pki_volume(): void
    {
        Process::fake([
            '*easyrsa*build-client-full*' => Process::result(output: ''),
            '*openssl*' => Process::result(output: "serial=ABC123\n"),
        ]);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id, 'name' => 'NAS Uji']);
        $server = VpnServer::factory()->create(['protocol' => 'openvpn', 'subnet_cidr' => '172.23.200.0/29']);
        $server->provisionIpPool();

        // The Process fake above doesn't actually write real cert files —
        // VpnScriptService reads them straight after provisioning, so seed
        // the files it expects to find.
        File::put($this->pkiDir."/issued/nas-{$nas->id}.crt", 'CLIENT-CERT-CONTENT');
        File::put($this->pkiDir."/private/nas-{$nas->id}.key", 'CLIENT-KEY-CONTENT');

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(VpnScriptGenerator::class)
            ->set('selectedNasId', $nas->id)
            ->set('routerOsVersion', '7')
            ->set('vpnProtocol', 'openvpn')
            ->call('generateVpn')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('vpn_accounts', ['nas_id' => $nas->id, 'protocol' => 'openvpn']);

        // The primary paste target is the short fetch+import one-liner, not
        // the raw script (see ScriptDownloadTokenService) — but the full
        // script is still kept on the component for the "lihat isi script"
        // audit view.
        $fetchCommand = $component->get('fetchCommand');
        $this->assertNotNull($fetchCommand);
        $this->assertStringContainsString('/tool fetch url="', $fetchCommand);
        $this->assertStringContainsString('/import file-name="boss-vpn-setup.rsc";', $fetchCommand);

        // Real RouterOS 7.11 testing found the previous approach (embedding
        // raw PEM as a "# ===== file.crt =====" comment header followed by
        // unescaped multi-line PEM text) fails /import with a syntax error —
        // every line of an imported script is parsed as a command, and raw
        // PEM text isn't valid RouterOS syntax. The fix fetches each file
        // via its own /tool fetch call instead of ever embedding PEM content
        // directly, so none of it should appear as literal text here.
        $script = $component->get('generatedScript');
        $this->assertNotNull($script);
        $this->assertStringNotContainsString('CLIENT-CERT-CONTENT', $script);
        $this->assertStringNotContainsString('CLIENT-KEY-CONTENT', $script);
        $this->assertStringNotContainsString('CA-CONTENT', $script);
        $this->assertSame(3, substr_count($script, '/tool fetch url='));
        $this->assertStringContainsString('/certificate import file-name=', $script);
    }

    public function test_wireguard_is_rejected_on_router_os_6(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(VpnScriptGenerator::class)
            ->set('selectedNasId', $nas->id)
            ->set('routerOsVersion', '6')
            ->set('vpnProtocol', 'wireguard')
            ->call('generateVpn');

        $this->assertStringContainsString('RouterOS 6.x', $component->get('errorMessage'));
        $this->assertNull($component->get('generatedScript'));
    }

    public function test_reseller_cannot_generate_script_for_another_resellers_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->forReseller($resellerB)->create();

        $ownerA = User::factory()->create(['tenant_id' => $tenant->id]);
        $resellerA->users()->attach($ownerA->id, ['role' => 'owner', 'status' => 'active']);

        Livewire::actingAs($ownerA)
            ->test(VpnScriptGenerator::class)
            ->set('selectedNasId', $nasB->id)
            ->call('generateVpn')
            ->assertForbidden();
    }

    private function fakeWireGuard(): void
    {
        Process::fake([
            '*wg*genkey*' => Process::result(output: "priv\n"),
            '*wg*pubkey*' => Process::result(output: "pub\n"),
        ]);
        File::put($this->pkiDir.'/server_public.key', 'SERVER-PUB-KEY');
        config([
            'services.vpn.wg_peers_dir' => $this->pkiDir.'/peers',
            // v0.8.1 — REQUIRED isolation, not optional tidiness: without
            // this, VpnProvisioningService::issueWireGuardCredentials()'s
            // address-fragment write falls back to its real default and
            // lands in the actual production /vpn-wg-data/addresses
            // volume — confirmed for real (a stray "nas-1.conf" with this
            // exact test's synthetic 172.23.200.0/29 data was found there
            // and cleaned up while building this fix).
            'services.vpn.wg_addresses_dir' => $this->pkiDir.'/addresses',
        ]);
    }

    public function test_regenerating_wireguard_for_an_already_provisioned_nas_offers_revoke_and_regenerate(): void
    {
        $this->fakeWireGuard();
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $server = VpnServer::factory()->create(['protocol' => 'wireguard', 'subnet_cidr' => '172.23.200.0/29']);
        $server->provisionIpPool();

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(VpnScriptGenerator::class)
            ->set('selectedNasId', $nas->id)
            ->set('routerOsVersion', '7')
            ->set('vpnProtocol', 'wireguard')
            ->call('generateVpn');

        $component->assertSet('canRevokeAndRegenerate', false);
        $this->assertNotNull($component->get('generatedScript'));

        // Second attempt for the SAME nas+protocol: the private key from the
        // first provision is already gone, so this must fail — but with the
        // recovery button now offered, not a dead-end message.
        $component2 = Livewire::actingAs($this->admin($tenant))
            ->test(VpnScriptGenerator::class)
            ->set('selectedNasId', $nas->id)
            ->set('routerOsVersion', '7')
            ->set('vpnProtocol', 'wireguard')
            ->call('generateVpn');

        $component2->assertSet('canRevokeAndRegenerate', true);
        $this->assertNull($component2->get('generatedScript'));
    }

    public function test_revoke_and_regenerate_replaces_the_old_wireguard_account_and_produces_a_fresh_script(): void
    {
        $this->fakeWireGuard();
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $server = VpnServer::factory()->create(['protocol' => 'wireguard', 'subnet_cidr' => '172.23.200.0/29']);
        $server->provisionIpPool();

        Livewire::actingAs($this->admin($tenant))
            ->test(VpnScriptGenerator::class)
            ->set('selectedNasId', $nas->id)
            ->set('vpnProtocol', 'wireguard')
            ->call('generateVpn');

        $oldAccountId = VpnAccount::where('nas_id', $nas->id)->where('status', 'active')->firstOrFail()->id;

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(VpnScriptGenerator::class)
            ->set('selectedNasId', $nas->id)
            ->set('vpnProtocol', 'wireguard')
            ->call('revokeAndRegenerate');

        $component->assertHasNoErrors();
        $this->assertNotNull($component->get('generatedScript'));
        $this->assertNull($component->get('errorMessage'));

        $this->assertDatabaseHas('vpn_accounts', ['id' => $oldAccountId, 'status' => 'revoked']);
        $newAccount = VpnAccount::where('nas_id', $nas->id)->where('status', 'active')->first();
        $this->assertNotNull($newAccount);
        $this->assertNotSame($oldAccountId, $newAccount->id);
    }

    /**
     * v0.16 hotfix regression (real incident 2026-08-31, ro-hotspot): a
     * host reboot left every WireGuard node offline. "Generate ulang"
     * used to revoke the NAS's last account FIRST, then fail the
     * reprovision with "no VPN server online" — stranding the NAS with
     * ZERO account. The capacity precheck must now run BEFORE the revoke.
     */
    public function test_revoke_and_regenerate_does_not_revoke_the_existing_account_when_every_node_is_offline(): void
    {
        $this->fakeWireGuard();
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $server = VpnServer::factory()->create(['protocol' => 'wireguard', 'subnet_cidr' => '172.23.200.0/29']);
        $server->provisionIpPool();

        Livewire::actingAs($this->admin($tenant))
            ->test(VpnScriptGenerator::class)
            ->set('selectedNasId', $nas->id)
            ->set('vpnProtocol', 'wireguard')
            ->call('generateVpn');

        $accountId = VpnAccount::where('nas_id', $nas->id)->where('status', 'active')->firstOrFail()->id;

        // node goes offline (host reboot, container down, …)
        $server->update(['status' => 'offline']);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(VpnScriptGenerator::class)
            ->set('selectedNasId', $nas->id)
            ->set('vpnProtocol', 'wireguard')
            ->call('revokeAndRegenerate');

        $this->assertStringContainsString('Tidak ada VPN server online', (string) $component->get('errorMessage'));
        // The existing account is UNTOUCHED — not revoked.
        $this->assertDatabaseHas('vpn_accounts', ['id' => $accountId, 'status' => 'active']);
        // Recovery button stays offered (there's still an active account).
        $component->assertSet('canRevokeAndRegenerate', true);
    }

    public function test_generate_vpn_surfaces_a_clean_error_instead_of_a_500_when_no_node_is_online(): void
    {
        $this->fakeWireGuard();
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        VpnServer::factory()->create(['protocol' => 'wireguard', 'status' => 'offline', 'subnet_cidr' => '172.23.200.0/29']);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(VpnScriptGenerator::class)
            ->set('selectedNasId', $nas->id)
            ->set('vpnProtocol', 'wireguard')
            ->call('generateVpn');

        $this->assertStringContainsString('Tidak ada VPN server online', (string) $component->get('errorMessage'));
        $this->assertDatabaseMissing('vpn_accounts', ['nas_id' => $nas->id, 'status' => 'active']);
    }

    /**
     * v0.8.1 end-to-end regression: a NAS with tr069_management_subnet set
     * must get the VPN node's own tunnel gateway address appended to
     * allowed-address (so traffic MASQUERADEd onto it by
     * docker/wireguard/entrypoint.sh isn't dropped by WireGuard's own
     * cryptokey routing before it ever reaches RouterOS) — the reserved
     * infra tunnel block itself (and its single route) is now unconditional
     * for every NAS regardless of tr069_management_subnet, replacing the
     * old per-service GenieACS NBI/CWMP routes this test used to assert
     * (see MikrotikScriptGenerator::wireGuardScript()'s own docblock for
     * the full v0.8.1 reasoning).
     */
    public function test_wireguard_script_for_a_nas_with_tr069_subnet_includes_the_vpn_nodes_tunnel_gateway(): void
    {
        $this->fakeWireGuard();
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create([
            'tenant_id' => $tenant->id,
            'tr069_management_subnet' => '10.1.0.0/20',
        ]);
        $server = VpnServer::factory()->create(['protocol' => 'wireguard', 'subnet_cidr' => '172.23.200.0/29']);
        $server->provisionIpPool();

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(VpnScriptGenerator::class)
            ->set('selectedNasId', $nas->id)
            ->set('routerOsVersion', '7')
            ->set('vpnProtocol', 'wireguard')
            ->call('generateVpn')
            ->assertHasNoErrors();

        $script = $component->get('generatedScript');
        $this->assertNotNull($script);
        $this->assertStringContainsString('/ip route add dst-address=172.28.0.224/27 gateway=boss-vpn-wireguard comment="boss-vpn-infra-block-route"', $script);
        // 172.23.200.1 — the reserved .1 of this VpnServer's own
        // subnet_cidr (172.23.200.0/29), i.e. its wg0 tunnel gateway.
        $this->assertStringContainsString('allowed-address=172.28.0.224/27,172.23.200.1/32', $script);
        // The dead whole-subnet route must never appear.
        $this->assertStringNotContainsString('boss-vpn-tr069-route', $script);
        $this->assertStringNotContainsString('dst-address=10.1.0.0/20', $script);
    }

    /**
     * v0.8.1 — the flip side of the test above: a NAS with NO
     * tr069_management_subnet still gets the infra block itself (it's
     * unconditional now), just without the extra VPN-node-tunnel-gateway
     * /32 appended.
     */
    public function test_wireguard_script_for_a_nas_without_tr069_subnet_still_gets_the_infra_block(): void
    {
        $this->fakeWireGuard();
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id, 'tr069_management_subnet' => null]);
        $server = VpnServer::factory()->create(['protocol' => 'wireguard', 'subnet_cidr' => '172.23.200.0/29']);
        $server->provisionIpPool();

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(VpnScriptGenerator::class)
            ->set('selectedNasId', $nas->id)
            ->set('routerOsVersion', '7')
            ->set('vpnProtocol', 'wireguard')
            ->call('generateVpn')
            ->assertHasNoErrors();

        $script = $component->get('generatedScript');
        $this->assertNotNull($script);
        $this->assertStringContainsString('/ip route add dst-address=172.28.0.224/27 gateway=boss-vpn-wireguard comment="boss-vpn-infra-block-route"', $script);
        $this->assertStringContainsString('allowed-address=172.28.0.224/27 ', $script);
        $this->assertStringNotContainsString('172.23.200.1/32', $script);
    }

    public function test_generating_radius_script_never_touches_nas_api_credentials(): void
    {
        // Regression test for a real bug: generateRadiusScript() used to
        // rotate nas.api_username/api_password as a side effect of mere
        // preview, even when the script was never applied to the router —
        // this is exactly the pattern that caused NAS to silently "go
        // offline" with the stored credential mismatching the router's
        // real one. Mikrotik API user provisioning is now a fully separate
        // action (NasApiUserProvisioningService) — see its own docblock.
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create([
            'tenant_id' => $tenant->id,
            'api_username' => 'existing-api-user',
            'api_password' => 'existing-api-password',
            'radius_secret' => 'secretvalue',
        ]);

        $component = Livewire::actingAs($this->admin($tenant))->test(VpnScriptGenerator::class)
            ->set('selectedNasId', $nas->id);

        // Called 5 times in a row, matching this project's own real-world
        // verification standard — must never mutate anything.
        for ($i = 0; $i < 5; $i++) {
            $component->call('generateRadius')->assertHasNoErrors();
        }

        $nas->refresh();
        $this->assertSame('existing-api-user', $nas->api_username);
        $this->assertSame('existing-api-password', $nas->api_password);
    }
}
