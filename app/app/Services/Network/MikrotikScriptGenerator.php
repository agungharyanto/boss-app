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
 * the same NAS never leaves an orphaned interface behind) and never routes
 * a default route through the tunnel, so a NAS's normal production traffic
 * is untouched (v0.6.3 locked decision). OpenVPN/L2TP route only
 * FreeRADIUS specifically; WireGuard (v0.8.1) routes the whole reserved
 * infra tunnel block (see wireGuardScript()'s own docblock) — still never
 * anything beyond that fixed, deliberately-scoped set of destinations.
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
            cipher=aes256-gcm auth=sha256 add-default-route=no disabled=no \\
            comment="BOSS App - OpenVPN client NAS {$account->username}"

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
     *
     * **v0.7.3's first cut of this (a single route to the NAS's WHOLE
     * `tr069_management_subnet`) was found dead on a real router**: that
     * subnet IS the NAS's own local LAN, so RouterOS's connected route to
     * it always wins over a static route through the tunnel — the route
     * was accepted but never actually used. What GenieACS Connection
     * Request genuinely needs instead is a reverse route so a CPE's reply
     * can find its way back through the tunnel instead of out the NAS's
     * normal WAN route — v0.7.3-v0.8.0 did this per-service (one `/32`
     * each for FreeRADIUS/GenieACS NBI/CWMP); v0.8.1 replaced that with a
     * single route for the whole reserved infra block instead (see this
     * method's own newer docblock below for why — $reverseRouteTargets no
     * longer exists as a parameter).
     *
     * **A second, separate gap in the same v0.7.3 cut, found by inspecting
     * the live wireguard-node3 container's own iptables/interface state
     * directly (not by more router testing)**: `docker/wireguard/
     * entrypoint.sh`'s TR069_MANAGEMENT_SUBNET MASQUERADE rule
     * (`POSTROUTING -o wg0 -d $TR069_MANAGEMENT_SUBNET -j MASQUERADE`)
     * rewrites GenieACS's real source IP to the VPN node's OWN tunnel
     * gateway address (confirmed live: wireguard-node3's wg0 has
     * `172.23.195.1/24`, i.e. the reserved `.1` — see
     * App\Support\CidrRange::gatewayAddress()) before the packet ever
     * reaches the router. WireGuard's cryptokey routing checks a decrypted
     * packet's SOURCE against the peer's own `allowed-address` and drops
     * it on mismatch — since neither FreeRADIUS's IP nor the (now-removed)
     * whole management subnet ever covered `172.23.195.1`, the forward leg
     * of a Connection Request would have been silently dropped by
     * WireGuard itself, before RouterOS's own firewall/routing ever saw
     * it, regardless of how many /ip route lines existed. $vpnNodeTunnelIp
     * is that address — added to allowed-address so the router ACCEPTS
     * packets sourced from it.
     *
     * **False lead, corrected same day — do NOT re-add a route for
     * $vpnNodeTunnelIp without new real evidence.** A route to
     * $vpnNodeTunnelIp (label `node-gateway`) was briefly added here,
     * reasoning by analogy from the allowed-address finding above: `nc -zv`
     * from genieacs-nbi toward two specific CPE IPs (10.1.12.87:7547,
     * 10.1.13.229:58000) kept timing out, which looked like proof a route
     * was missing. It wasn't — those two IPs turned out to be STALE
     * (DHCP-leased management IPs from the original v0.7.3 investigation,
     * ~2 weeks earlier, that no device holds anymore — confirmed absent
     * from all 220 devices' current `ConnectionRequestURL` values pulled
     * fresh from GenieACS). Retesting against real, currently-reported
     * ConnectionRequestURLs (5 ZTE F663NV3a, 8 Huawei EG8141A5) succeeded
     * for every single device — 5/5 ZTE on the first attempt, 8/8 Huawei
     * within 3 retries (the first attempt to a given IP occasionally timed
     * out, most likely ARP-cache-miss latency on the router's local segment
     * exceeding nc's own connect timeout — every retry to an already-tried
     * IP succeeded instantly) — **with zero code or router changes applied
     * beyond the allowed-address fix already in place**. So Connection
     * Request routing was never actually broken; the `node-gateway` route
     * was reverted. Lesson: always confirm a CPE's CURRENT
     * ConnectionRequestURL from GenieACS itself before treating a timeout
     * against a remembered IP as evidence of anything.
     *
     * **`/ip address add` here used a single CIDR `/32` value from
     * v0.6.3 through v0.8.0, never a separate `network=` parameter** —
     * checked via `git log -p` back to this method's introduction to be
     * sure before assuming otherwise. A real `test-x86-bajastu` config
     * found showing Address/Network as two split fields (`172.23.195.2` /
     * `172.23.195.1`) did not come from this generator — it was applied
     * outside BOSS App (manually). `/32` was also the deliberately
     * correct choice at the time, not just what happened to be generated:
     * a wider mask would have made RouterOS auto-add a connected route
     * for the WHOLE shared `/24` (every NAS's gateway lived in the same
     * subnet back then), defeating the explicit per-service reverse-route
     * isolation this method exists to enforce. **v0.8.1 changed this to
     * `/30`** — see the `/ip address add` line's own comment further down
     * for why that reasoning no longer applies once each NAS has its own
     * dedicated /30 (VpnWireguardNasBlock) instead of sharing one /24.
     *
     * v0.8.1 — $infraBlockCidr replaces the old $reverseRouteTargets array
     * (one /32 per service, hand-added to the router for every new
     * module). It's now a single reserved /27 (INFRA_TUNNEL_BLOCK_CIDR in
     * .env, e.g. "172.28.0.224/27") covering every boss-network container
     * that legitimately needs to reach through a NAS's tunnel
     * (FreeRADIUS, GenieACS CWMP/NBI, LibreNMS, LibreNMS-dispatcher, and
     * room for future modules) — a brand-new module just needs a free IP
     * inside this block, the router config never needs touching again.
     * DELIBERATE widening of the v0.6.2 hub-and-spoke "/32 only" posture,
     * confirmed explicitly with Agung, not scope drift — see CLAUDE.md's
     * "Infra Tunnel IP Block" section for the full reasoning.
     *
     * $freeradiusInternalIp is still taken separately (not derived from
     * the block) — it's only used for autoSwitchBlock()'s own health-check
     * ping, unrelated to allowed-address/routing.
     *
     * Idempotent regen (found necessary from a real manual mix-up: Agung
     * hand-edited allowed-address in Winbox once, and the old /32 entries
     * were never cleaned up because nothing ever re-ran the generated
     * script's own remove step) — the single infra-block route is
     * preceded by a WILDCARD removal (`comment~"boss-vpn-.*-route"`, not
     * an exact match on one comment) specifically so re-pasting this
     * script also sweeps away the 3 old per-service routes
     * (boss-vpn-freeradius-route/-genieacs-nbi-route/-genieacs-cwmp-route)
     * from any NAS still on the pre-v0.8.1 scheme, in the same run that
     * adds the new block route — no separate manual Winbox cleanup step
     * needed for the migration.
     *
     * **A second, real orphan-entry bug found applying the FIRST /27-block
     * script to test-x86-bajastu**: `/ip address remove [find
     * interface="{$ifaceName}"]` used to run AFTER {$cleanup} (above) had
     * already destroyed and recreated the wireguard interface — the OLD
     * address entry's interface binding is by internal object id, not the
     * display name string, so once that specific interface object is gone
     * the entry shows as attached to interface "unknown" and a
     * by-interface find silently matches nothing, leaving an orphaned
     * duplicate in `/ip address print` on every single regen (confirmed
     * from a real Winbox screenshot: an orphaned "BOSS App - WAN VPN
     * address NAS nas-1" entry with interface "unknown"). Same root cause
     * class as the route staleness above, same fix: `/ip address remove
     * [find comment~"BOSS App - WAN VPN address"]` — the comment survives
     * regardless of what happened to the interface object underneath it.
     * Audited every other `[find ...]` removal in this class for the same
     * "reference to something removed earlier in the same script" pattern
     * (interfaceCleanupBlock(), routingIsolationBlock(), autoSwitchBlock(),
     * openVpnScript()'s certificate/file cleanup, radiusScript()) — none
     * of the others are interface-identity-dependent (they key off a
     * static name or comment that isn't affected by removing/recreating a
     * DIFFERENT object), so this was the only one with the bug.
     */
    public function wireGuardScript(
        VpnAccount $account,
        string $publicIp,
        int $port,
        string $serverPublicKey,
        string $clientPrivateKey,
        string $infraBlockCidr,
        string $freeradiusInternalIp,
        array $nodePorts = [],
        ?string $nasGatewayIp = null,
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

        $allowedAddress = $infraBlockCidr;

        // v0.8.1 — $nasGatewayIp replaces $vpnNodeTunnelIp: no longer the
        // single address shared by every NAS (172.23.195.1), now this
        // NAS's OWN dedicated /30 gateway (VpnWireguardNasBlock). Still
        // gated the same way as before (only added when this NAS actually
        // needs MASQUERADE-based reverse routing for TR069/OLT traffic —
        // see VpnScriptService::wireGuardScriptOrThrow()) — the PURPOSE
        // hasn't changed (accepting packets MASQUERADEd onto the node's
        // own tunnel identity before crossing), only WHICH address serves
        // that role per NAS.
        if ($nasGatewayIp !== null) {
            $allowedAddress .= ",{$nasGatewayIp}/32";
        }

        $infraBlockRouteComment = $this->infraBlockRouteComment();

        return <<<SCRIPT
        # BOSS App — WireGuard client script untuk NAS "{$account->username}"
        # Generated: RouterOS 7.x ONLY (WireGuard tidak ada di RouterOS 6.x)
        # PERINGATAN: private key di bawah HANYA ditampilkan SEKALI saat ini —
        # BOSS App tidak menyimpannya. Simpan script ini sendiri jika perlu
        # re-apply nanti (revoke + provision ulang kalau hilang).

        :log info "BOSS App: konfigurasi ulang WireGuard client ({$ifaceName})"

        {$cleanup}

        /interface wireguard add name={$ifaceName} private-key="{$clientPrivateKey}" disabled=no \\
            comment="BOSS App - WireGuard interface NAS {$account->username}"

        /interface wireguard peers add interface={$ifaceName} public-key="{$serverPublicKey}" \\
            endpoint-address={$publicIp} endpoint-port={$port} \\
            allowed-address={$allowedAddress} persistent-keepalive=25s \\
            comment="BOSS App - WireGuard peer NAS {$account->username}"

        # comment-based find, NOT [find interface="{$ifaceName}"] — the
        # interface this address was attached to was already destroyed and
        # recreated by the cleanup block above (different internal object,
        # same display name), so the OLD address entry's interface binding
        # is now "unknown" and a by-interface find silently matches
        # nothing, leaving an orphaned duplicate every single regen. Same
        # class of staleness bug as the old per-service /ip route entries,
        # same fix (comment survives regardless of what happened to the
        # interface).
        #
        # v0.8.1 — /30, NOT /32 anymore. This is a DELIBERATE REVERSAL of
        # the v0.7.3 decision ("a wider mask would make RouterOS auto-add
        # a connected route for the whole subnet, defeating the reverse-
        # route isolation") — do NOT "fix" this back to /32 without first
        # reading CLAUDE.md's "WireGuard /30 Per-NAS Tunnel Blocks"
        # section. The difference: v0.7.3's subnet was WG_SUBNET_CIDR, a
        # /24 SHARED by every NAS (a connected route for the whole /24
        # would have been genuinely wrong/wide). Here the /30 belongs to
        # THIS ONE NAS alone (VpnWireguardNasBlock) — a connected route
        # for a /30 only ever covers 2 usable hosts (this NAS's own
        # gateway + its own router address), so it's exactly as narrow as
        # the old /32 was, just now backed by a REAL connected route
        # instead of relying purely on WireGuard's own AllowedIPs-driven
        # implicit routing for the reverse direction (suspected — not yet
        # conclusively proven — contributor to traffic arriving at the
        # router but not being forwarded onward, per real rx-counter
        # evidence gathered investigating this).
        /ip address remove [find comment~"BOSS App - WAN VPN address"]
        /ip address add address={$account->internal_ip}/30 interface={$ifaceName} \\
            comment="BOSS App - WAN VPN address NAS {$account->username}"

        # allowed-address di atas HANYA filter kripto/interface — TIDAK
        # otomatis mengisi routing table (dibuktikan lewat tes ping asli:
        # handshake berhasil tapi paket ICMP tidak pernah sampai tanpa baris
        # di bawah ini). Satu route untuk seluruh blok infra wajib supaya
        # traffic balasan dari servis mana pun di dalam blok itu benar-benar
        # lewat tunnel ini. Wildcard remove dulu (bukan exact-match) supaya
        # sisa route model lama (per-servis /32, sebelum v0.8.1) ikut
        # tersapu otomatis di sini juga, tanpa langkah manual terpisah.
        /ip route remove [find comment~"boss-vpn-.*-route"]
        /ip route add dst-address={$infraBlockCidr} gateway={$ifaceName} comment="{$infraBlockRouteComment}"
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
            ipsec-secret="{$psk}" use-ipsec=yes add-default-route=no disabled=no \\
            comment="BOSS App - L2TP/IPsec client NAS {$account->username}"

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
        /system script add name={$scriptName} source="{$sourceOneLiner}" comment="BOSS App - auto-switch logic {$ifaceName}"
        /system scheduler remove [find name={$schedulerName}]
        /system scheduler add name={$schedulerName} interval=30s on-event="{$scriptName}" comment="BOSS App - auto-switch scheduler {$ifaceName}"
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

    /**
     * v0.8.1 — replaces the old per-service reverseRouteComment('label')
     * (one comment per /32, v0.7.3-v0.8.0) now that wireGuardScript()
     * generates exactly ONE route for the whole infra block. The old
     * per-service comments ('boss-vpn-freeradius-route', etc.) are still
     * what the wildcard `comment~"boss-vpn-.*-route"` removal in
     * wireGuardScript() sweeps up during migration — this new comment is
     * deliberately named differently ('-infra-block-' not tied to any one
     * service) so it's obviously a different, newer kind of route to
     * anyone reading Winbox's route list.
     */
    private function infraBlockRouteComment(): string
    {
        return 'boss-vpn-infra-block-route';
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
