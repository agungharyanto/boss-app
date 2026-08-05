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

    public function test_openvpn_script_has_no_autoswitch_block_when_only_one_node_online(): void
    {
        $account = $this->vpnAccount(['username' => 'nas-42', 'protocol' => VpnProtocol::OpenVpn]);

        $script = $this->generator->openVpnScript(
            $account, '7', '45.123.142.242', 1194, '172.28.0.10',
            'ca-url', 'cert-url', 'key-url', 'https', [1194],
        );

        $this->assertStringNotContainsString('/system scheduler add', $script);
        $this->assertStringContainsString('cuma 1 node online', $script);
    }

    public function test_openvpn_script_autoswitch_block_cycles_through_every_online_node_port(): void
    {
        $account = $this->vpnAccount(['username' => 'nas-42', 'protocol' => VpnProtocol::OpenVpn]);

        $script = $this->generator->openVpnScript(
            $account, '7', '45.123.142.242', 1194, '172.28.0.10',
            'ca-url', 'cert-url', 'key-url', 'https', [1194, 1195, 1196],
        );

        // 3 real bugs found deploying this to test-x86-bajastu, in order:
        // (1) `on-event={...}` inline never persisted (empty on a live
        // .proplist query) — fixed by pointing on-event at a separate
        // script's NAME instead, matching this router's own pre-existing
        // schedulers. (2) That script's OWN `source={...}` (same
        // curly-brace multi-line block) ALSO never persisted — fixed by
        // making source= a single-line, semicolon-separated quoted STRING
        // instead (interface names left unquoted throughout so that outer
        // string never needs nested-quote escaping). (3) Once source= held
        // real content, every single $variable inside it had been silently
        // expanded to empty at /import time (RouterOS evaluates
        // "$var"-in-a-quoted-string against whatever's in scope AT IMPORT,
        // not the script's own later runtime) — confirmed by sending the
        // exact same string directly over the API instead of a .rsc file,
        // where it stored perfectly literally with no such expansion.
        // Fixed by writing a literal backslash before every $ (\$var, not
        // just $var) — confirmed directly against the router that /import
        // then stores it as a literal $var too.
        $this->assertStringContainsString('/system script add name=boss-vpn-autoswitch-openvpn-script source=', $script);
        $this->assertStringContainsString('/system scheduler add name=boss-vpn-autoswitch-openvpn interval=30s on-event="boss-vpn-autoswitch-openvpn-script"', $script);
        $this->assertStringContainsString(':local currentPort [/interface ovpn-client get [find name=boss-vpn-openvpn] port];', $script);
        $this->assertStringContainsString(':local pingOk [:ping 172.28.0.10 interface=boss-vpn-openvpn count=3];', $script);
        // Wraps at the max port back to the min port (1194..1196). Every
        // $variable reference below must carry its literal backslash.
        $this->assertStringContainsString(':if (\$nextPort > 1196) do={:set nextPort 1194};', $script);
        $this->assertStringContainsString('/interface ovpn-client set [find name=boss-vpn-openvpn] port=\$nextPort disabled=yes;', $script);
        $this->assertStringContainsString('/interface ovpn-client set [find name=boss-vpn-openvpn] disabled=no', $script);
        // No unescaped/embedded double-quote inside the outer source="..."
        // string — the whole one-liner must be quote-free internally.
        $this->assertMatchesRegularExpression('/source="[^"]*"/', $script);
    }

    public function test_wireguard_script_autoswitch_block_uses_endpoint_port_not_disabled_toggle(): void
    {
        $account = $this->vpnAccount(['username' => 'nas-42', 'protocol' => VpnProtocol::WireGuard, 'internal_ip' => '172.23.195.10']);

        $script = $this->generator->wireGuardScript(
            $account, '45.123.142.242', 51820, '172.28.0.10', 'SERVERPUB==', 'CLIENTPRIV==',
            [51820, 51821, 51822],
        );

        $this->assertStringContainsString('/system scheduler add name=boss-vpn-autoswitch-wireguard interval=30s on-event="boss-vpn-autoswitch-wireguard-script"', $script);
        $this->assertStringContainsString(':local currentPort [/interface wireguard peers get [find interface=boss-vpn-wireguard] endpoint-port];', $script);
        $this->assertStringContainsString('/interface wireguard peers set [find interface=boss-vpn-wireguard] endpoint-port=\$nextPort', $script);
        // WireGuard's autoswitch never needs OpenVPN's disable/re-enable
        // toggle — the one-liner must close its do={...} block immediately
        // after the endpoint-port change.
        $this->assertStringContainsString('endpoint-port=\$nextPort}', $script);
        $this->assertMatchesRegularExpression('/source="[^"]*"/', $script);
    }

    public function test_radius_script_uses_the_nas_own_dynamic_ports(): void
    {
        $nas = $this->nas([
            'name' => 'NAS Gambir',
            'radius_secret' => 'nas-radius-secret',
            'auth_port' => 27189,
            'acct_port' => 27190,
        ]);

        $script = $this->generator->radiusScript($nas, '172.28.0.10', 'boss-api', 'apipass123');

        // v0.6.5: the actual `/radius add` command (spans two physical
        // lines via a trailing `\` continuation) must use THIS NAS's own
        // unique auth/acct ports, never the old shared default (1812/1813)
        // — that was the v0.6.3-v0.6.4 interim behavior.
        $this->assertStringContainsString('authentication-port=27189', $script);
        $this->assertStringContainsString('accounting-port=27190', $script);
        $this->assertStringNotContainsString('authentication-port=1812', $script);
        $this->assertStringNotContainsString('accounting-port=1813', $script);
        $this->assertStringContainsString('secret="nas-radius-secret"', $script);
        $this->assertStringContainsString('policy=read,api,!local,!telnet,!ssh,!ftp,!reboot,!write', $script);
    }
}
