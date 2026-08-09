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

    // v0.7.3 — separate comment from IROUTE_ROUTE_COMMENT above on purpose:
    // the two routes are independently added/removed (a NAS's
    // tr069_management_subnet can be null while its FreeRADIUS route still
    // exists), so `find comment=...` in the WireGuard script's cleanup step
    // must not accidentally match/remove the other one.
    private const TR069_ROUTE_COMMENT = 'boss-vpn-tr069-route';

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
    /**
     * $nodePorts: every ONLINE node's port for this protocol at generation
     * time (including this account's own $port), used to build the
     * auto-switch scheduler block below — pass an empty array to omit
     * auto-switch entirely (e.g. only one node exists/is online, nothing
     * to fail over to).
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
        array $nodePorts = [],
    ): string {
        $ifaceName = 'boss-vpn-openvpn';
        $caFile = "{$account->username}-ca.crt";
        $certFile = "{$account->username}.crt";
        $keyFile = "{$account->username}.key";
        $cleanup = $this->interfaceCleanupBlock();
        $routing = $this->routingIsolationBlock($routerOsVersion, $ifaceName, $freeradiusInternalIp);
        $autoSwitch = $this->autoSwitchBlock(
            schedulerName: 'boss-vpn-autoswitch-openvpn',
            freeradiusInternalIp: $freeradiusInternalIp,
            nodePorts: $nodePorts,
            ifaceName: $ifaceName,
            menuPath: '/interface ovpn-client',
            findKey: 'name',
            portProperty: 'port',
            needsReEnable: true,
        );

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

        {$autoSwitch}
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
        array $nodePorts = [],
        ?string $tr069ManagementSubnet = null,
    ): string {
        $ifaceName = 'boss-vpn-wireguard';
        $cleanup = $this->interfaceCleanupBlock();
        $autoSwitch = $this->autoSwitchBlock(
            schedulerName: 'boss-vpn-autoswitch-wireguard',
            freeradiusInternalIp: $freeradiusInternalIp,
            nodePorts: $nodePorts,
            ifaceName: $ifaceName,
            menuPath: '/interface wireguard peers',
            findKey: 'interface',
            portProperty: 'endpoint-port',
            needsReEnable: false,
        );

        // v0.7.3 — widen allowed-address to also admit this NAS's TR-069
        // management subnet (when set), so GenieACS Connection Request can
        // reach CPE behind it. Same "allowed-address doesn't auto-populate
        // the routing table" gotcha documented above applies here too — a
        // second explicit /ip route is required, not just the widened
        // allowed-address on its own.
        $allowedAddress = "{$freeradiusInternalIp}/32";
        $tr069RouteBlock = '';

        if ($tr069ManagementSubnet !== null) {
            $allowedAddress .= ",{$tr069ManagementSubnet}";
            $tr069RouteBlock = <<<BLOCK

            # v0.7.3 — GenieACS Connection Request ke subnet manajemen
            # TR-069 NAS ini, sama alasan seperti route FreeRADIUS di atas
            # (allowed-address tidak otomatis mengisi routing table).
            /ip route remove [find comment="{$this->tr069RouteComment()}"]
            /ip route add dst-address={$tr069ManagementSubnet} gateway={$ifaceName} \\
                comment="{$this->tr069RouteComment()}"
            BLOCK;
        }

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
            allowed-address={$allowedAddress} persistent-keepalive=25s

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
        {$tr069RouteBlock}
        :log info "BOSS App: WireGuard client {$ifaceName} selesai dikonfigurasi"

        {$autoSwitch}
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
     * v0.6.5: FreeRADIUS now runs a real dynamic virtual server per NAS
     * (App\Services\Network\FreeradiusVirtualServerService) — this script
     * uses $nas->auth_port/acct_port for real, no longer the shared default
     * 1812/1813.
     *
     * Real bug found running this script for real (fetch+import) against
     * test-x86-bajastu (RouterOS 7.11): the `/user group add ... policy=`
     * line (removed since — see below) included `!dude`, a RouterOS
     * 6.x-era policy keyword that doesn't exist anymore on 7.x. RouterOS
     * rejects the ENTIRE policy string the instant one token doesn't match
     * a known keyword ("input does not match any value of policy"), which
     * looked identical to a genuine permission error and was initially
     * misdiagnosed as one.
     *
     * Deliberately does NOT touch `/user`/`/user group` at all anymore
     * (it did in earlier v0.6.5 iterations) — found, and fixed, a real bug
     * where THIS method itself rotated nas.api_username/api_password as a
     * side effect of merely being called to produce preview text, even
     * when the resulting script was never actually run on the router
     * (VpnScriptService::generateRadiusScript() is documented read-only
     * now). Router-side API user creation/rotation is a separate, explicit
     * action — see App\Services\Network\NasApiUserProvisioningService —
     * executed directly via the RouterOS API, not through a downloadable
     * script, specifically so it can be a deliberate one-shot call rather
     * than a byproduct of viewing something.
     *
     * NasService::create() always allocates auth_port/acct_port
     * immediately (NasPortAllocatorService), so a NAS reaching this method
     * without them would mean the allocator itself was bypassed — not a
     * state this method tries to paper over.
     */
    public function radiusScript(Nas $nas, string $freeradiusInternalIp): string
    {
        return <<<SCRIPT
        # BOSS App — RADIUS setup script untuk NAS "{$nas->name}"
        # Port auth/acct unik untuk NAS ini: {$nas->auth_port}/{$nas->acct_port}
        # (dynamic virtual server FreeRADIUS, v0.6.5 — bukan lagi port
        # default 1812/1813 bersama).

        :log info "BOSS App: konfigurasi ulang RADIUS untuk NAS {$nas->name}"

        /radius remove [find comment="boss-radius"]
        /radius add service=ppp,hotspot address={$freeradiusInternalIp} \\
            secret="{$nas->radius_secret}" authentication-port={$nas->auth_port} accounting-port={$nas->acct_port} \\
            timeout=3s comment="boss-radius"

        /ppp aaa set use-radius=yes
        /ip hotspot profile set [find] use-radius=yes

        :log info "BOSS App: RADIUS untuk NAS {$nas->name} selesai dikonfigurasi"
        SCRIPT;
    }

    /**
     * v0.6.4 multi-node pool auto-switch — pattern referenced from the
     * MixRadius "AutoSwitchVPN.rsc" reference script dissected at the start
     * of the v0.6.0 cluster: a RouterOS scheduler pings FreeRADIUS through
     * the tunnel periodically, and on failure, switches this NAS's
     * connect-to endpoint to a sibling node of the same protocol.
     *
     * Deliberately uses plain integer arithmetic (next port = current + 1,
     * wrapping min<->max), NOT array/:find-based lookup — every sibling
     * node's port is allocated sequentially by VpnServersSeeder (1194/1195/
     * 1196, 51820/51821/51822), so "the next candidate" is always just
     * "+1, wrap at the range boundary". This avoids relying on RouterOS
     * script array-search semantics that are harder to verify without a
     * real device, in favor of the simplest, most robust operation
     * available (integer comparison/assignment, unambiguous in every
     * RouterOS version). All sibling nodes share the SAME public_ip this
     * sprint (one physical server, see v0.6.4 architecture decision) — only
     * the port ever needs to change, never connect-to itself.
     *
     * Returns an empty string (no scheduler at all) when $nodePorts has
     * fewer than 2 entries — nothing to fail over to.
     *
     * **Two real bugs found and fixed via actual deployment to
     * test-x86-bajastu (RouterOS 7.11), both the same root cause**: the
     * first version wrote `/system scheduler add ... on-event={ ...multi-
     * line script... }` directly — the scheduler WAS created, but
     * `/system scheduler print` showed `on-event` as a genuinely EMPTY
     * string (confirmed via an explicit `.proplist=on-event` query, not
     * just a truncated display). Switched to this router's own proven
     * pattern (its pre-existing `schedule-script-speed` scheduler) —
     * define the logic as its own `/system script`, point `on-event` at
     * that script's NAME (a plain quoted string) instead of inline code.
     * That fixed the scheduler, but the SAME curly-brace-block problem
     * then showed up one level down: `/system script add ... source={
     * ...multi-line... }` ALSO left `source` empty on real hardware.
     * Root cause both times: `/import`-run files apparently never
     * correctly capture a `{ ... }` block spanning multiple LINES of the
     * .rsc file as a single parameter value, unlike typing it interactively
     * at a console. Fixed by making `source=` a single-line, semicolon-
     * separated STRING instead (same proven idiom as this whole module's
     * fetch+import one-liner) — and to avoid nested-quote escaping inside
     * that now-quoted source string entirely, interface names are used
     * UNQUOTED throughout this method (RouterOS doesn't require quotes for
     * an identifier with no spaces, and `boss-vpn-*` names never have any).
     */
    private function autoSwitchBlock(
        string $schedulerName,
        string $freeradiusInternalIp,
        array $nodePorts,
        string $ifaceName,
        string $menuPath,
        string $findKey,
        string $portProperty,
        bool $needsReEnable,
    ): string {
        $nodePorts = array_values(array_unique($nodePorts));

        if (count($nodePorts) < 2) {
            return '# Auto-switch tidak disertakan — cuma 1 node online untuk protokol ini saat script digenerate, tidak ada node lain untuk gagal-pindah.';
        }

        $minPort = min($nodePorts);
        $maxPort = max($nodePorts);
        $scriptName = "{$schedulerName}-script";
        $findExpr = "[find {$findKey}={$ifaceName}]";

        // A LITERAL backslash followed by a dollar sign — NOT a PHP
        // interpolation guard (that would just be "\$", which PHP reduces
        // to a bare "$" in the output, no backslash survives). Real bug
        // found via a real device: when a .rsc file run through /import
        // contains `source="...$pingOk..."`, RouterOS expands $pingOk as a
        // variable reference AT IMPORT TIME (in whatever scope /import
        // itself is running in, where none of these locals exist yet), so
        // every one of them silently evaluated to an empty string —
        // confirmed via a live .proplist=source query showing
        // "current + )" instead of "$current + )". Sending the exact same
        // source string directly over the RouterOS API (not through a
        // .rsc file) stores $-variables completely literally with no such
        // expansion — the API and the /import command-line parser
        // genuinely behave differently here. The fix, also confirmed
        // directly against the router: prefixing every $ with a real
        // backslash (\$var, not just $var) makes /import store it as a
        // literal $var too, matching what the API does natively.
        $dollar = '\\$';

        $setPort = "{$menuPath} set {$findExpr} {$portProperty}={$dollar}nextPort";
        $reEnable = '';
        if ($needsReEnable) {
            $setPort .= ' disabled=yes';
            $reEnable = ";{$menuPath} set {$findExpr} disabled=no";
        }

        $sourceOneLiner = ":local currentPort [{$menuPath} get {$findExpr} {$portProperty}];"
            .":local pingOk [:ping {$freeradiusInternalIp} interface={$ifaceName} count=3];"
            .":if ({$dollar}pingOk = 0) do={:local nextPort ({$dollar}currentPort + 1);:if ({$dollar}nextPort > {$maxPort}) do={:set nextPort {$minPort}};{$setPort}{$reEnable}}";

        return <<<SCRIPT
        # Auto-switch: cek FreeRADIUS tiap 30 detik lewat tunnel ini, kalau
        # gagal 3x ping berturut-turut, pindah ke node lain (siklus
        # {$minPort}..{$maxPort}, urutan dari VpnServersSeeder v0.6.4).
        /system script remove [find name={$scriptName}]
        /system script add name={$scriptName} source="{$sourceOneLiner}"
        /system scheduler remove [find name={$schedulerName}]
        /system scheduler add name={$schedulerName} interval=30s on-event="{$scriptName}"
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

    private function tr069RouteComment(): string
    {
        return self::TR069_ROUTE_COMMENT;
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
