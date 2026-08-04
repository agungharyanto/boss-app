<?php

namespace App\Services\Network;

use App\Models\Nas;
use App\Models\VpnAccount;

/**
 * Pure script templating — no DB/file I/O of its own (the caller supplies
 * whatever plaintext material is available; see
 * VpnScriptGenerator Livewire component for why that availability differs
 * per protocol). Every script is idempotent (removes any pre-existing
 * VPN client interface of ANY of the 4 PPP-based types — not just the one
 * being generated — before adding fresh ones, so switching protocols on
 * the same NAS never leaves an orphaned interface behind) and routes ONLY
 * FreeRADIUS traffic through the tunnel — never a default route — so a
 * NAS's normal production traffic is untouched (v0.6.3 locked decision).
 *
 * Not verified against a real Mikrotik device (none available in this
 * environment) — same caveat as the v0.4.0 WhatsApp QR-delivery-vs-actual-
 * scan gap documented in CLAUDE.md.
 */
class MikrotikScriptGenerator
{
    private const IROUTE_TABLE = 'boss-vpn-table';

    private const ROUTING_MARK = 'boss-vpn-mark';

    private const IROUTE_RULE_COMMENT = 'boss-vpn-rule';

    private const IROUTE_ROUTE_COMMENT = 'boss-vpn-freeradius-route';

    private const MANGLE_COMMENT = 'boss-vpn-mangle';

    /**
     * $caCertUrl/$clientCertUrl/$clientKeyUrl are short-lived, single-use
     * download URLs (see ScriptDownloadTokenService), NOT the raw PEM
     * content itself. Found via a real RouterOS 7.11 test: an earlier
     * version of this method embedded the raw PEM text directly in the
     * .rsc file as a "# ===== file.crt =====" comment header followed by
     * unescaped multi-line PEM — every line of a script run through
     * /import is parsed as a RouterOS command, and raw PEM text is not
     * valid command syntax, so importing failed with a syntax error the
     * moment a real certificate/key was involved (small scripts with no
     * cert content "worked" only because they never hit this code path).
     * The fix is to never put PEM content in the script body at all: this
     * script instead fetches each file itself via /tool fetch (the exact
     * same non-interactive download mechanism already used for the outer
     * .rsc script — see ScriptDownloadTokenService's own docblock) right
     * before importing it, so the router's local file store — not this
     * script's own text — ever holds the raw certificate/key bytes.
     */
    public function openVpnScript(
        VpnAccount $account,
        string $routerOsVersion,
        string $publicIp,
        int $port,
        string $freeradiusInternalIp,
        string $caCertUrl,
        string $clientCertUrl,
        string $clientKeyUrl,
        string $fetchMode,
    ): string {
        $ifaceName = 'boss-vpn-openvpn';
        $caFile = "{$account->username}-ca.crt";
        $certFile = "{$account->username}.crt";
        $keyFile = "{$account->username}.key";
        $cleanup = $this->interfaceCleanupBlock();
        $routing = $this->routingIsolationBlock($routerOsVersion, $ifaceName, $freeradiusInternalIp);

        return <<<SCRIPT
        # BOSS App — OpenVPN client script untuk NAS "{$account->username}"
        # Generated: RouterOS {$routerOsVersion}.x, cipher aes256-gcm
        #
        # Certificate/private key TIDAK di-embed sebagai teks di script ini —
        # diambil otomatis lewat /tool fetch dari 3 link sekali-pakai BOSS
        # App (sama seperti script ini sendiri diambil), langsung sebelum
        # di-import. Tidak ada langkah manual upload file.

        :log info "BOSS App: konfigurasi ulang OpenVPN client ({$ifaceName})"

        {$cleanup}

        /tool fetch url="{$caCertUrl}" mode={$fetchMode} dst-path="{$caFile}"
        /tool fetch url="{$clientCertUrl}" mode={$fetchMode} dst-path="{$certFile}"
        /tool fetch url="{$clientKeyUrl}" mode={$fetchMode} dst-path="{$keyFile}"

        /certificate remove [find name="{$caFile}"]
        /certificate remove [find name="{$certFile}"]
        /certificate remove [find name="{$keyFile}"]

        /certificate import file-name={$caFile} passphrase=""
        /certificate import file-name={$certFile} passphrase=""
        /certificate import file-name={$keyFile} passphrase=""

        /file remove [find name="{$caFile}"]
        /file remove [find name="{$certFile}"]
        /file remove [find name="{$keyFile}"]

        /interface ovpn-client add name={$ifaceName} connect-to={$publicIp} port={$port} protocol=udp \\
            certificate={$certFile} user={$account->username} verify-server-certificate=no \\
            cipher=aes256-gcm auth=sha256 add-default-route=no disabled=no

        # Isolasi routing: HANYA traffic ke FreeRADIUS yang lewat tunnel ini.
        # Routing default NAS produksi TIDAK disentuh (add-default-route=no
        # di atas + routing terpisah di bawah).
        {$routing}

        :log info "BOSS App: OpenVPN client {$ifaceName} selesai dikonfigurasi"
        SCRIPT;
    }

    /**
     * RouterOS 7.0+ ONLY — WireGuard support does not exist at all on
     * RouterOS 6.x. Caller (Livewire component) is responsible for not
     * offering this option when routerOsVersion is 6.x.
     *
     * Fully self-contained (no file upload step) — both keys are inline,
     * unlike OpenVPN's cert files, and single-line base64 strings (no
     * newlines/special characters), so — unlike OpenVPN's multi-line PEM —
     * there's no /import syntax-error risk from embedding them directly as
     * quoted command arguments.
     *
     * **Explicit /ip route is REQUIRED, contrary to this method's own
     * earlier assumption** — found via a real RouterOS 7.11 ping test: the
     * peer's `allowed-address` alone got a real WireGuard handshake and
     * even some traffic (control-channel bytes), but tcpdump on the
     * server-side wg0 interface showed literal zero ICMP packets ever
     * arriving during a `/ping` test, and `/ip route print` confirmed there
     * was no active route to FreeRADIUS via this interface at all —
     * `allowed-address` on a RouterOS WireGuard peer is a crypto/interface-
     * level accept-filter only, it does NOT auto-populate the routing
     * table the way some other WireGuard implementations' AllowedIPs do.
     * Now adds an explicit static route, same idea as OpenVPN/L2TP's
     * routingIsolationBlock() but simpler — WireGuard needs no routing-
     * mark/routing-table indirection (allowed-address already prevents any
     * OTHER traffic from ever being encrypted through this tunnel, so a
     * plain route is sufficient, not just "safe enough").
     */
    public function wireGuardScript(
        VpnAccount $account,
        string $publicIp,
        int $port,
        string $freeradiusInternalIp,
        string $serverPublicKey,
        string $clientPrivateKey,
    ): string {
        $ifaceName = 'boss-vpn-wireguard';
        $cleanup = $this->interfaceCleanupBlock();

        return <<<SCRIPT
        # BOSS App — WireGuard client script untuk NAS "{$account->username}"
        # Generated: RouterOS 7.x ONLY (WireGuard tidak ada di RouterOS 6.x)
        # PERINGATAN: private key di bawah HANYA ditampilkan SEKALI saat ini —
        # BOSS App tidak menyimpannya. Simpan script ini sendiri jika perlu
        # re-apply nanti (revoke + provision ulang kalau hilang).

        :log info "BOSS App: konfigurasi ulang WireGuard client ({$ifaceName})"

        {$cleanup}

        /interface wireguard add name={$ifaceName} private-key="{$clientPrivateKey}" disabled=no

        /interface wireguard peers add interface={$ifaceName} public-key="{$serverPublicKey}" \\
            endpoint-address={$publicIp} endpoint-port={$port} \\
            allowed-address={$freeradiusInternalIp}/32 persistent-keepalive=25s

        /ip address remove [find interface="{$ifaceName}"]
        /ip address add address={$account->internal_ip}/32 interface={$ifaceName}

        # allowed-address di atas HANYA filter kripto/interface — TIDAK
        # otomatis mengisi routing table (dibuktikan lewat tes ping asli:
        # handshake berhasil tapi paket ICMP tidak pernah sampai tanpa baris
        # di bawah ini). Route eksplisit wajib supaya traffic ke FreeRADIUS
        # benar-benar lewat tunnel ini.
        /ip route remove [find comment="{$this->routeComment()}"]
        /ip route add dst-address={$freeradiusInternalIp}/32 gateway={$ifaceName} \\
            comment="{$this->routeComment()}"

        :log info "BOSS App: WireGuard client {$ifaceName} selesai dikonfigurasi"
        SCRIPT;
    }

    public function l2tpScript(
        VpnAccount $account,
        string $routerOsVersion,
        string $publicIp,
        string $freeradiusInternalIp,
        string $psk,
    ): string {
        $ifaceName = 'boss-vpn-l2tp';
        $cleanup = $this->interfaceCleanupBlock();
        $routing = $this->routingIsolationBlock($routerOsVersion, $ifaceName, $freeradiusInternalIp);

        return <<<SCRIPT
        # BOSS App — L2TP/IPsec client script untuk NAS "{$account->username}"
        # Generated: RouterOS {$routerOsVersion}.x (syntax l2tp-client sama di v6/v7)

        :log info "BOSS App: konfigurasi ulang L2TP/IPsec client ({$ifaceName})"

        {$cleanup}

        /interface l2tp-client add name={$ifaceName} connect-to={$publicIp} \\
            user={$account->username} password="{$account->password}" \\
            ipsec-secret="{$psk}" use-ipsec=yes add-default-route=no disabled=no

        # Isolasi routing — sama pola dengan OpenVPN (l2tp-client juga tidak
        # punya mekanisme AllowedIPs bawaan seperti WireGuard).
        {$routing}

        :log info "BOSS App: L2TP/IPsec client {$ifaceName} selesai dikonfigurasi"
        SCRIPT;
    }

    /**
     * v0.6.3 decision B (confirmed explicitly): FreeRADIUS only has ONE
     * default virtual server (port 1812/1813) until the dynamic per-NAS
     * virtual server + port allocator ships in v0.6.5 — this script
     * deliberately uses that default port, NOT $nas->auth_port/acct_port,
     * even though those columns exist and are already unique per NAS since
     * v0.6.1. Upgrading this to use them is v0.6.5 scope, not a bug here.
     */
    public function radiusScript(Nas $nas, string $freeradiusInternalIp, string $apiUsername, string $apiPassword): string
    {
        $assignedPorts = $nas->auth_port !== null && $nas->acct_port !== null
            ? "{$nas->auth_port}/{$nas->acct_port}"
            : 'belum diisi, menunggu v0.6.5';

        return <<<SCRIPT
        # BOSS App — RADIUS setup script untuk NAS "{$nas->name}"
        #
        # SEMENTARA memakai port default FreeRADIUS (1812/1813) — BUKAN
        # nas.auth_port/acct_port ({$assignedPorts}),
        # walau kolom itu sudah ada sejak v0.6.1. FreeRADIUS baru bisa
        # dengar di port unik per-NAS setelah dynamic virtual server +
        # port allocator (v0.6.5) selesai — script ini WAJIB digenerate
        # ulang setelah v0.6.5 shipped supaya benar-benar pakai port unik.

        :log info "BOSS App: konfigurasi ulang RADIUS untuk NAS {$nas->name}"

        /radius remove [find comment="boss-radius"]
        /radius add service=ppp,hotspot address={$freeradiusInternalIp} \\
            secret="{$nas->radius_secret}" authentication-port=1812 accounting-port=1813 \\
            timeout=3s comment="boss-radius"

        /ppp aaa set use-radius=yes
        /ip hotspot profile set [find] use-radius=yes

        # User API terbatas khusus BOSS App (read-only + api, tanpa write/
        # winbox/telnet/ssh/ftp/password/sensitive) — dipakai NasService's
        # RouterOsGateway (v0.6.1) untuk test-connection, bukan untuk
        # mengubah konfigurasi NAS.
        /user group remove [find name="boss-api-readonly"]
        /user group add name=boss-api-readonly \\
            policy=read,api,!local,!telnet,!ssh,!ftp,!reboot,!write,!policy,!test,!winbox,!password,!web,!sniff,!sensitive,!romon,!dude

        /user remove [find name="{$apiUsername}"]
        /user add name={$apiUsername} group=boss-api-readonly password="{$apiPassword}"

        :log info "BOSS App: RADIUS untuk NAS {$nas->name} selesai dikonfigurasi"
        SCRIPT;
    }

    /**
     * Removes every BOSS-managed VPN client interface, regardless of which
     * protocol is about to be (re)configured — ovpn-client, sstp-client
     * (SSTP is permanently skipped per the v0.6.0 decision, but cleaned up
     * anyway in case one was ever manually configured), l2tp-client,
     * pptp-client (never generated by BOSS App at all, same reasoning), and
     * the WireGuard interface + its peer. Scoped ONLY to BOSS App's own
     * fixed interface names (`boss-vpn-*`) — never a blanket
     * "remove every ovpn-client on the router", which could delete an
     * admin's own unrelated VPN config. Found necessary during manual
     * testing: switching protocols on the same NAS left the previous
     * protocol's interface behind.
     */
    private function interfaceCleanupBlock(): string
    {
        return <<<'SCRIPT'
        /interface ovpn-client remove [find name="boss-vpn-openvpn"]
        /interface sstp-client remove [find name="boss-vpn-sstp"]
        /interface l2tp-client remove [find name="boss-vpn-l2tp"]
        /interface pptp-client remove [find name="boss-vpn-pptp"]
        /interface wireguard peers remove [find interface="boss-vpn-wireguard"]
        /interface wireguard remove [find name="boss-vpn-wireguard"]
        SCRIPT;
    }

    /**
     * Branches by RouterOS major version — found during manual testing that
     * the original implementation ALWAYS used the v7-only `/routing table`
     * + `routing-table=` mechanism regardless of $routerOsVersion, which
     * doesn't exist at all on RouterOS 6.x (`/routing table` is a v7
     * routing-subsystem redesign — v6 uses `routing-mark=` directly on
     * `/ip route add`, paired with a mangle rule to actually mark the
     * matching traffic). Both branches always include `dst-address=` on
     * `/ip route add` — the isolation only holds if the route itself is
     * scoped to FreeRADIUS's address, not just reachable via the tunnel
     * interface.
     */
    private function routingIsolationBlock(string $routerOsVersion, string $ifaceName, string $freeradiusInternalIp): string
    {
        if ($routerOsVersion === '6') {
            return <<<SCRIPT
            /ip firewall mangle remove [find comment="{$this->mangleComment()}"]
            /ip firewall mangle add chain=output dst-address={$freeradiusInternalIp}/32 \\
                action=mark-routing new-routing-mark={$this->routingMark()} passthrough=no \\
                comment="{$this->mangleComment()}"
            /ip route remove [find comment="{$this->routeComment()}"]
            /ip route add dst-address={$freeradiusInternalIp}/32 gateway={$ifaceName} \\
                routing-mark={$this->routingMark()} comment="{$this->routeComment()}"
            SCRIPT;
        }

        return <<<SCRIPT
        /routing table remove [find name="{$this->routingTable()}"]
        /routing table add name={$this->routingTable()} fib
        /ip route remove [find comment="{$this->routeComment()}"]
        /ip route add dst-address={$freeradiusInternalIp}/32 gateway={$ifaceName} \\
            routing-table={$this->routingTable()} comment="{$this->routeComment()}"
        /routing rule remove [find comment="{$this->ruleComment()}"]
        /routing rule add dst-address={$freeradiusInternalIp}/32 action=lookup \\
            table={$this->routingTable()} comment="{$this->ruleComment()}"
        SCRIPT;
    }

    private function routingTable(): string
    {
        return self::IROUTE_TABLE;
    }

    private function routingMark(): string
    {
        return self::ROUTING_MARK;
    }

    private function routeComment(): string
    {
        return self::IROUTE_ROUTE_COMMENT;
    }

    private function ruleComment(): string
    {
        return self::IROUTE_RULE_COMMENT;
    }

    private function mangleComment(): string
    {
        return self::MANGLE_COMMENT;
    }
}
