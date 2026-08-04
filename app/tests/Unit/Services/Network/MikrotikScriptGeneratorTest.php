<?php

namespace Tests\Unit\Services\Network;

use App\Enums\VpnProtocol;
use App\Models\Nas;
use App\Models\VpnAccount;
use App\Services\Network\MikrotikScriptGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MikrotikScriptGeneratorTest extends TestCase
{
    private MikrotikScriptGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new MikrotikScriptGenerator;
    }

    /**
     * Plain `new Model([...])` (never persisted, no factory) — this is a
     * pure templating unit test, no DB should be involved at all. Using
     * `Model::factory()->make()` here would still eagerly INSERT a related
     * Tenant row (a well-known Laravel factory gotcha: a nested
     * `Model::factory()` value inside another factory's definition() is
     * resolved eagerly even under ->make()), which this class has no
     * RefreshDatabase/migrated schema for.
     */
    private function vpnAccount(array $attributes): VpnAccount
    {
        return new VpnAccount($attributes);
    }

    private function nas(array $attributes): Nas
    {
        return new Nas($attributes);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function routerOsVersions(): array
    {
        return ['RouterOS 6.x' => ['6'], 'RouterOS 7.x' => ['7']];
    }

    #[DataProvider('routerOsVersions')]
    public function test_openvpn_route_always_includes_dst_address_scoped_to_freeradius(string $routerOsVersion): void
    {
        $account = $this->vpnAccount(['username' => 'nas-42', 'protocol' => VpnProtocol::OpenVpn]);

        $script = $this->generator->openVpnScript(
            $account, $routerOsVersion, '45.123.142.242', 1194, '172.28.0.10',
            'https://boss.local/download/ca-token.rsc',
            'https://boss.local/download/cert-token.rsc',
            'https://boss.local/download/key-token.rsc',
            'https',
        );

        // The whole point of the isolation is that the route is scoped to
        // FreeRADIUS's address specifically — gateway/routing-mark alone
        // (without dst-address) would not limit which traffic uses it.
        $this->assertMatchesRegularExpression(
            '/\/ip route add dst-address=172\.28\.0\.10\/32 gateway=boss-vpn-openvpn/',
            $script
        );
        // No raw PEM content is ever embedded — each file is fetched by the
        // router itself via its own short-lived URL (see
        // MikrotikScriptGenerator::openVpnScript()'s docblock for why: a
        // real RouterOS /import syntax error when PEM text was embedded
        // directly).
        $this->assertStringContainsString('/tool fetch url="https://boss.local/download/ca-token.rsc" mode=https', $script);
        $this->assertStringContainsString('/tool fetch url="https://boss.local/download/cert-token.rsc" mode=https', $script);
        $this->assertStringContainsString('/tool fetch url="https://boss.local/download/key-token.rsc" mode=https', $script);
        $this->assertStringContainsString('/certificate import file-name=', $script);
        $this->assertStringNotContainsString('-----BEGIN', $script);
        $this->assertStringContainsString('add-default-route=no', $script);
    }

    #[DataProvider('routerOsVersions')]
    public function test_l2tp_route_always_includes_dst_address_scoped_to_freeradius(string $routerOsVersion): void
    {
        $account = $this->vpnAccount([
            'username' => 'nas-42',
            'protocol' => VpnProtocol::L2tpIpsec,
            'password' => 'l2tp-pass-value',
        ]);

        $script = $this->generator->l2tpScript($account, $routerOsVersion, '45.123.142.242', '172.28.0.10', 'global-psk-value');

        $this->assertMatchesRegularExpression(
            '/\/ip route add dst-address=172\.28\.0\.10\/32 gateway=boss-vpn-l2tp/',
            $script
        );
        $this->assertStringContainsString('user=nas-42', $script);
        $this->assertStringContainsString('password="l2tp-pass-value"', $script);
        $this->assertStringContainsString('ipsec-secret="global-psk-value"', $script);
        $this->assertStringContainsString('add-default-route=no', $script);
    }

    public function test_router_os_6_uses_routing_mark_not_the_v7_only_routing_table_mechanism(): void
    {
        $account = $this->vpnAccount(['username' => 'nas-42', 'protocol' => VpnProtocol::OpenVpn]);

        $script = $this->generator->openVpnScript($account, '6', '45.123.142.242', 1194, '172.28.0.10', 'ca-url', 'cert-url', 'key-url', 'https');

        // /routing table doesn't exist at all on RouterOS 6.x — using it
        // there would make the whole script fail on a real v6 router.
        $this->assertStringNotContainsString('/routing table', $script);
        $this->assertStringNotContainsString('/routing rule', $script);
        $this->assertStringContainsString('routing-mark=boss-vpn-mark', $script);
        $this->assertStringContainsString('/ip firewall mangle add', $script);
        $this->assertStringContainsString('new-routing-mark=boss-vpn-mark', $script);
        $this->assertStringContainsString('action=mark-routing', $script);
    }

    public function test_router_os_7_uses_routing_table_and_rule_mechanism(): void
    {
        $account = $this->vpnAccount(['username' => 'nas-42', 'protocol' => VpnProtocol::OpenVpn]);

        $script = $this->generator->openVpnScript($account, '7', '45.123.142.242', 1194, '172.28.0.10', 'ca-url', 'cert-url', 'key-url', 'https');

        $this->assertStringContainsString('/routing table add name=boss-vpn-table fib', $script);
        $this->assertStringContainsString('routing-table=boss-vpn-table', $script);
        $this->assertStringContainsString('/routing rule add dst-address=172.28.0.10/32 action=lookup', $script);
        $this->assertStringNotContainsString('/ip firewall mangle', $script);
        $this->assertStringNotContainsString('routing-mark=', $script);
    }

    /**
     * Found via manual testing: switching protocols on the same NAS left
     * the previous protocol's interface behind. Every generated script
     * (whichever protocol) must clean up ALL FOUR PPP-based client
     * interface types plus WireGuard, scoped to BOSS App's own fixed
     * interface names only.
     */
    public function test_every_vpn_script_removes_all_four_client_interface_types_and_wireguard(): void
    {
        $account = $this->vpnAccount(['username' => 'nas-42', 'protocol' => VpnProtocol::OpenVpn]);
        $wgAccount = $this->vpnAccount(['username' => 'nas-42', 'protocol' => VpnProtocol::WireGuard, 'internal_ip' => '172.23.195.5']);
        $l2tpAccount = $this->vpnAccount(['username' => 'nas-42', 'protocol' => VpnProtocol::L2tpIpsec, 'password' => 'pw']);

        $scripts = [
            $this->generator->openVpnScript($account, '7', '45.123.142.242', 1194, '172.28.0.10', 'ca-url', 'cert-url', 'key-url', 'https'),
            $this->generator->wireGuardScript($wgAccount, '45.123.142.242', 51820, '172.28.0.10', 'pub', 'priv'),
            $this->generator->l2tpScript($l2tpAccount, '7', '45.123.142.242', '172.28.0.10', 'psk'),
        ];

        foreach ($scripts as $script) {
            $this->assertStringContainsString('/interface ovpn-client remove [find name="boss-vpn-openvpn"]', $script);
            $this->assertStringContainsString('/interface sstp-client remove [find name="boss-vpn-sstp"]', $script);
            $this->assertStringContainsString('/interface l2tp-client remove [find name="boss-vpn-l2tp"]', $script);
            $this->assertStringContainsString('/interface pptp-client remove [find name="boss-vpn-pptp"]', $script);
            $this->assertStringContainsString('/interface wireguard remove [find name="boss-vpn-wireguard"]', $script);
        }
    }

    public function test_wireguard_script_embeds_both_keys_inline_and_scopes_allowed_address_to_freeradius_only(): void
    {
        $account = $this->vpnAccount([
            'username' => 'nas-42',
            'protocol' => VpnProtocol::WireGuard,
            'internal_ip' => '172.23.195.10',
        ]);

        $script = $this->generator->wireGuardScript(
            $account, '45.123.142.242', 51820, '172.28.0.10', 'SERVERPUB==', 'CLIENTPRIV==',
        );

        $this->assertStringContainsString('private-key="CLIENTPRIV=="', $script);
        $this->assertStringContainsString('public-key="SERVERPUB=="', $script);
        $this->assertStringContainsString('allowed-address=172.28.0.10/32', $script);
        $this->assertStringContainsString('address=172.23.195.10/32', $script);
        // No full-tunnel 0.0.0.0/0 allowed-address anywhere.
        $this->assertStringNotContainsString('0.0.0.0/0', $script);
    }

    public function test_radius_script_uses_default_freeradius_ports_not_nas_specific_ports(): void
    {
        $nas = $this->nas([
            'name' => 'NAS Gambir',
            'radius_secret' => 'nas-radius-secret',
            'auth_port' => 27189,
            'acct_port' => 27190,
        ]);

        $script = $this->generator->radiusScript($nas, '172.28.0.10', 'boss-api', 'apipass123');

        $this->assertStringContainsString('authentication-port=1812', $script);
        $this->assertStringContainsString('accounting-port=1813', $script);
        // The NAS's own unique ports (27189/27190) may appear in the
        // informational comment (telling the admin what v0.6.5 will
        // eventually use) but must NEVER appear in the actual `/radius add`
        // command line itself.
        $radiusAddLine = collect(explode(PHP_EOL, $script))->first(fn ($l) => str_starts_with(trim($l), '/radius add'));
        $this->assertStringNotContainsString('27189', $radiusAddLine);
        $this->assertStringNotContainsString('27190', $radiusAddLine);
        $this->assertStringContainsString('secret="nas-radius-secret"', $script);
        $this->assertStringContainsString('policy=read,api,!local,!telnet,!ssh,!ftp,!reboot,!write', $script);
    }

    public function test_radius_script_handles_unassigned_nas_ports_gracefully(): void
    {
        $nas = $this->nas(['auth_port' => null, 'acct_port' => null]);

        $script = $this->generator->radiusScript($nas, '172.28.0.10', 'boss-api', 'apipass123');

        $this->assertStringContainsString('belum diisi, menunggu v0.6.5', $script);
    }
}
