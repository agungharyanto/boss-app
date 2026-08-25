#!/bin/bash
set -euo pipefail

WG_DIR=/etc/wireguard
PEERS_DIR="$WG_DIR/peers"
ADDRESSES_DIR="$WG_DIR/addresses"

: "${WG_LISTEN_PORT:?WG_LISTEN_PORT must be set}"
: "${WG_SUBNET_CIDR:?WG_SUBNET_CIDR must be set}"                  # e.g. 172.23.195.0/24
: "${FREERADIUS_INTERNAL_IP:?FREERADIUS_INTERNAL_IP must be set}"
: "${NODE_HOSTNAME:?NODE_HOSTNAME must be set}"
# v0.8.1 — WG_SUBNET_NETWORK_ADDR is NO LONGER READ. Through v0.8.0 every
# NAS shared ONE gateway address (this env var, always .1 of
# WG_SUBNET_CIDR), applied here as a single static `ip address add`. Each
# NAS now gets its own dedicated /30 block (VpnWireguardNasBlock) instead
# — wg0's addresses are applied dynamically from $ADDRESSES_DIR/*.conf
# fragments (one per NAS, written by VpnProvisioningService, same pattern
# as $PEERS_DIR/*.conf for peers) inside the reconcile loop below, not a
# single value known ahead of time. See CLAUDE.md's "WireGuard /30
# Per-NAS Tunnel Blocks" section for the full reasoning. The env var
# itself is harmless to leave set in .env (unused, not an error) — kept
# out of the required-vars list above rather than actively rejected, so a
# server that hasn't cleaned up its old .env yet doesn't fail to boot.
#
# WG_NAS_GATEWAY_IP — deliberately optional, SAME "one NAS only, static/
# manual, known limitation" posture as TR069_MANAGEMENT_GATEWAY/
# OLT_MANAGEMENT_GATEWAY below. Needed for the MASQUERADE `--to-source`
# fix (see the POSTROUTING block further down) — with per-NAS gateways,
# plain `-j MASQUERADE` (no explicit source) is ambiguous the moment wg0
# carries more than one address, so this pins it to the ONE NAS whose
# TR069/OLT reverse routing is actually configured right now. A genuinely
# multi-NAS-aware version of this (matching destination subnet to the
# correct NAS's own gateway automatically) is NOT built here — flagged
# as a real generalization gap, not silently hardcoded without a trace.
WG_NAS_GATEWAY_IP="${WG_NAS_GATEWAY_IP:-}"
# v0.7.3 — Connection Request routing. Deliberately optional (unset/empty
# skips the exception below entirely) — not every deployment has a NAS with
# tr069_management_subnet configured yet. GENIEACS_CWMP_INTERNAL_IP/
# GENIEACS_NBI_INTERNAL_IP must be pinned static IPs (docker-compose.yml),
# same "must not silently drift on container recreation" reasoning as
# FREERADIUS_INTERNAL_IP.
TR069_MANAGEMENT_SUBNET="${TR069_MANAGEMENT_SUBNET:-}"
GENIEACS_CWMP_INTERNAL_IP="${GENIEACS_CWMP_INTERNAL_IP:-}"
GENIEACS_NBI_INTERNAL_IP="${GENIEACS_NBI_INTERNAL_IP:-}"

# v0.8.1 — LibreNMS OLT SNMP polling. Same "deliberately optional, static/
# manual, one subnet only" posture as TR069_MANAGEMENT_SUBNET above — added
# ALONGSIDE it, never replacing or modifying that block (see below). Unlike
# the GenieACS exception (scoped to 2 specific service /32s),
# INFRA_TUNNEL_BLOCK_CIDR is used as the source here — the whole point of
# the v0.8.1 reserved /27 is that a new module (LibreNMS today, something
# else later) inside that block doesn't need its own entrypoint.sh edit.
OLT_MANAGEMENT_SUBNET="${OLT_MANAGEMENT_SUBNET:-}"
INFRA_TUNNEL_BLOCK_CIDR="${INFRA_TUNNEL_BLOCK_CIDR:-}"

mkdir -p "$PEERS_DIR" "$ADDRESSES_DIR"

# Server keypair persists on the shared vpn_wg_data volume (also mounted
# into boss-app) — generated once, unlike wg0 itself which is re-created
# fresh every container start (network namespace state, not volume state).
#
# v0.6.4: this volume is now ALSO shared with sibling nodes (wireguard-
# node2/node3, see docker-compose.yml) so all 3 nodes present the
# IDENTICAL public key — flock (not just docker-compose's depends_on,
# which only waits for "container started", not "keygen finished")
# guards against a genuine first-boot race if two sibling containers
# both reach this check before either has written the file yet.
(
    flock -x 200
    if [ ! -f "$WG_DIR/server_private.key" ]; then
        echo ">> [entrypoint] Generating WireGuard server keypair (first boot for this volume)..."
        wg genkey | tee "$WG_DIR/server_private.key" | wg pubkey > "$WG_DIR/server_public.key"
    fi
) 200>"$WG_DIR/.keygen.lock"

# boss-app (php-fpm workers run as www-data, different UID) needs to read
# server_public.key (to embed in generated Mikrotik scripts) and read/write
# peers/*.conf — same "shared volume, permissive chmod" call already made
# for vpn_pki/vpn_ccd in v0.6.2.
#
# $WG_DIR itself (the vpn_wg_data volume mount point) must ALSO be made
# traversable, not just its children — found via a real revoke-and-
# regenerate 500 error: wireguard-tools' own alpine package ships
# /etc/wireguard as 0700 root:root, which Docker preserves into the named
# volume on first mount. Chmod'ing only server_private.key/server_public.key
# and peers/ (as this entrypoint did before) left www-data unable to even
# traverse INTO $WG_DIR to reach those otherwise-permissive children —
# File::isDirectory()/makeDirectory() in VpnProvisioningService failed with
# EACCES before ever reaching the (already 0777) peers/ directory.
chmod 0755 "$WG_DIR"
chmod 0644 "$WG_DIR/server_private.key" "$WG_DIR/server_public.key"
chmod -R 0777 "$PEERS_DIR" "$ADDRESSES_DIR"

SERVER_PRIVATE_KEY=$(cat "$WG_DIR/server_private.key")

# wg0 is network-namespace state, not volume state — always (re)created
# fresh on every container start. The `ip link del` guards against a plain
# `docker restart` (same container, same netns) where a previous run's wg0
# might still exist.
#
# v0.8.1 — no static `ip address add` here anymore (see WG_SUBNET_NETWORK_ADDR's
# own comment above). wg0 starts with NO address at all on a fresh
# container start; the reconcile loop below applies every known NAS's own
# /30 gateway address from $ADDRESSES_DIR fragments on its very first
# cycle (within ~10s), same latency window peers already have.
ip link del dev wg0 2>/dev/null || true
ip link add dev wg0 type wireguard
wg set wg0 listen-port "$WG_LISTEN_PORT" private-key "$WG_DIR/server_private.key"
ip link set up dev wg0

# --- Hub-and-spoke isolation (same 3-layer approach as openvpn's
# entrypoint.sh, v0.6.2) — WireGuard has no protocol-level "push route" to
# a client at all (routing is entirely the peer's own local decision), so
# this iptables allowlist is the ONLY enforcement layer here, not one of
# three like OpenVPN's ccd+push-route+iptables. ---
iptables -F FORWARD
iptables -P FORWARD DROP
# v0.8.4 — widened from a single "-d $FREERADIUS_INTERNAL_IP" to the
# WHOLE reserved infra block. Found genuinely necessary, not just a
# tidy-up: the router already has a block-wide `/ip route` (v0.8.1,
# comment "boss-vpn-infra-block-route") and a matching block-wide
# `allowed-address` — this FORWARD rule was the ONE remaining place
# still gatekeeping per-service by a single IP, confirmed for real while
# planning the rsyslog receiver (172.28.0.230): a router-initiated
# packet toward it would have been silently DROPped by this exact rule's
# old single-IP scope despite routing/WireGuard-layer trust already
# covering the whole block. Widening here is what actually delivers on
# the block's original promise ("a brand-new module just needs a free
# IP... router's allowed-address never needs touching again") — every
# FUTURE module in this block now also needs zero entrypoint.sh change,
# not just this one. The CoA rule below (FreeRADIUS-initiated, opposite
# direction) is deliberately NOT widened the same way — nothing else in
# this block currently needs to INITIATE a connection toward a NAS the
# way FreeRADIUS's CoA/Disconnect does.
iptables -A FORWARD -i wg0 -d "$INFRA_TUNNEL_BLOCK_CIDR" -j ACCEPT
iptables -A FORWARD -o wg0 -d "$WG_SUBNET_CIDR" -m state --state ESTABLISHED,RELATED -j ACCEPT

# v0.6.5 CoA/Disconnect — same narrow, deliberate reverse exception as
# openvpn/entrypoint.sh (see its own comment for the full rationale):
# NEW connections sourced from FreeRADIUS's own static IP, out through
# wg0, reaching a NAS's internal_ip. NOTE (known limitation, not a bug):
# only the SIBLING node a given NAS currently has an actual live handshake
# with can successfully deliver — this container has no way to send data
# to a peer it has never itself handshaked with (WireGuard, unlike
# OpenVPN, needs a learned endpoint per peer). CoaService therefore only
# reliably reaches a NAS that hasn't (recently) auto-switched away from
# its originally-provisioned node — see CoaService's own docblock.
iptables -A FORWARD -i eth0 -o wg0 -s "$FREERADIUS_INTERNAL_IP" -j ACCEPT

# v0.7.3 — GenieACS Connection Request into a NAS's TR-069 management
# subnet (e.g. test-x86-bajastu's 10.1.0.0/20, confirmed for real via
# direct RouterOS API query — see CLAUDE.md "GenieACS Vendor Parameter
# Mapping"). Deliberately scoped to ONLY genieacs-cwmp/genieacs-nbi's own
# pinned source IPs, never a wider boss-network allow — this is an
# addition to the existing hub-and-spoke allowlist above, not a
# replacement; the FreeRADIUS rules above are untouched. Static per-NAS
# subnet only (no per-account dynamic sync yet, known limitation — see
# CLAUDE.md): if this NAS's WireGuard account ever moves to a different
# pool node, this same rule (baked into the shared entrypoint.sh image)
# applies identically on whichever node it lands on, so no per-node
# tracking is needed for THAT part — but genieacs-cwmp/genieacs-nbi's own
# route to reach this subnet (added manually, not by this script) still
# points at a specific node's boss-network IP and needs updating by hand
# if the account moves.
if [ -n "$TR069_MANAGEMENT_SUBNET" ]; then
    # Found missing 2026-08-19, during the Connection Request (v0.7.3)
    # investigation: AllowedIPs on the peer entry only governs WireGuard's
    # own cryptokey routing (which peer to encrypt for / which decrypted
    # source to accept) — it does NOT, by itself, make the KERNEL choose
    # wg0 as the outbound interface for a packet destined into
    # $TR069_MANAGEMENT_SUBNET. Without this explicit route, genieacs-nbi's
    # connection_request packets never even reached this node's -o wg0
    # iptables rules above (confirmed via tcpdump + iptables counters: the
    # packet arrived on eth0 with the exact right src/dst, but 0 hits on
    # every -o wg0 rule, all matching the FORWARD DROP policy instead,
    # because the kernel's routing decision picked eth0's default route,
    # never wg0, before iptables was even consulted). `replace`, not `add`
    # — wg0 is recreated fresh every container start (see the comment
    # above `ip link add dev wg0`), so this must be idempotent across a
    # plain `docker restart` reusing the same netns, same reasoning as the
    # genieacs entrypoint's own `ip route replace` for the mirror route on
    # the genieacs-cwmp/genieacs-nbi side.
    #
    # v0.8.1 — RESTORED after a full OSPF rollback (see CLAUDE.md's
    # "OSPF Dynamic Routing" section: the OSPF experiment briefly removed
    # this line in favor of a FRR-sidecar-managed static route, then was
    # abandoned in favor of the simpler fragment+reconcile mechanism
    # below — this line is transient too, about to be replaced again by
    # that mechanism reading from $ROUTES_DIR instead of this fixed env
    # var, in the same sprint).
    ip route replace "$TR069_MANAGEMENT_SUBNET" dev wg0

    if [ -n "$GENIEACS_CWMP_INTERNAL_IP" ]; then
        iptables -A FORWARD -i eth0 -o wg0 -s "$GENIEACS_CWMP_INTERNAL_IP" -d "$TR069_MANAGEMENT_SUBNET" -j ACCEPT
    fi
    if [ -n "$GENIEACS_NBI_INTERNAL_IP" ]; then
        iptables -A FORWARD -i eth0 -o wg0 -s "$GENIEACS_NBI_INTERNAL_IP" -d "$TR069_MANAGEMENT_SUBNET" -j ACCEPT
    fi
    iptables -A FORWARD -o eth0 -i wg0 -s "$TR069_MANAGEMENT_SUBNET" -m state --state ESTABLISHED,RELATED -j ACCEPT
fi

# v0.8.1 — LibreNMS SNMP polling into a NAS's OLT management subnet (e.g.
# test-x86-bajastu's 10.168.100.0/24, found to be genuinely unroutable from
# librenms-dispatcher at all — see CLAUDE.md "LibreNMS OLT Onboarding" for
# the full diagnosis). Deliberately a SEPARATE if-block from the
# TR069_MANAGEMENT_SUBNET one above, not a merge/generalization of it — the
# two subnets are independent per-NAS values (this same NAS has BOTH a
# 10.1.0.0/20 CPE subnet and a 10.168.100.0/24 OLT subnet, and either one
# can be set without the other), and the whole point of Tahap 4 was to add
# to this file incrementally without touching what already works.
#
# Source is INFRA_TUNNEL_BLOCK_CIDR (the whole reserved /27), not 2
# individual /32s the way the GenieACS exception above does — this is the
# actual point of the v0.8.1 redesign: a future module sharing this same
# block doesn't need its own entrypoint.sh edit to reach a configured
# management subnet, only a free IP inside the block (see
# MikrotikScriptGenerator::wireGuardScript()'s docblock for the router-side
# half of this same reasoning).
#
# KNOWN LIMITATION, same class as TR069_MANAGEMENT_SUBNET's own: the
# MASQUERADE below (see the POSTROUTING block further down) rewrites the
# real source IP to this node's own wg0 gateway address before the packet
# crosses the tunnel — the router's allowed-address only accepts that
# rewritten address ($vpnNodeTunnelIp) when VpnScriptService adds it, which
# currently only happens when nas.tr069_management_subnet is set (see
# VpnScriptService::wireGuardScriptOrThrow()). For test-x86-bajastu this is
# already true (it has both subnets configured), so this works end-to-end
# today — but a FUTURE NAS with an OLT subnet and NO TR069 subnet would NOT
# get $vpnNodeTunnelIp in allowed-address, and this MASQUERADEd traffic
# would be silently dropped by WireGuard's own cryptokey routing. Not fixed
# here — doing so properly needs a real nas.olt_management_subnet column
# (this env var is a single global value, same one-subnet-only limitation
# TR069_MANAGEMENT_SUBNET already has), out of scope for this incremental
# container-side change.
if [ -n "$OLT_MANAGEMENT_SUBNET" ] && [ -n "$INFRA_TUNNEL_BLOCK_CIDR" ]; then
    # v0.8.1 — RESTORED after a full OSPF rollback, same as the
    # TR069_MANAGEMENT_SUBNET block above (same "transient, about to be
    # replaced by fragment+reconcile" note applies here too).
    ip route replace "$OLT_MANAGEMENT_SUBNET" dev wg0

    iptables -A FORWARD -i eth0 -o wg0 -s "$INFRA_TUNNEL_BLOCK_CIDR" -d "$OLT_MANAGEMENT_SUBNET" -j ACCEPT
    iptables -A FORWARD -o eth0 -i wg0 -s "$OLT_MANAGEMENT_SUBNET" -m state --state ESTABLISHED,RELATED -j ACCEPT
fi

iptables -t nat -F POSTROUTING

# v0.8.1 — REVERSE_NAT_TARGET: plain `-j MASQUERADE` (no explicit source)
# picks "whatever address the outgoing interface currently has" —
# unambiguous through v0.8.0 (wg0 only ever had ONE address), no longer
# safe now that wg0 can carry several (one /30 gateway per NAS, see
# $ADDRESSES_DIR below).
#
# REAL BUG found deploying this for the first time, not just reasoned
# about: `--to-source` is an SNAT-target option, MASQUERADE doesn't accept
# it at all — `iptables -j MASQUERADE --to-source ...` fails outright
# ("unknown option") and crash-loops the container (set -euo pipefail
# exits the whole script on that one failed command). The fix isn't
# "add an argument to MASQUERADE", it's switching the TARGET itself to
# SNAT when a specific source is known.
#
# v0.8.4 — STILL pinned to the single global WG_NAS_GATEWAY_IP, used
# below ONLY for the TR069/OLT management-subnet rules now (the
# FreeRADIUS rule this variable used to also drive is generated
# per-NAS inside the reconcile loop instead — see that loop's own
# comment for the real production bug this fixes). TR069_MANAGEMENT_
# SUBNET/OLT_MANAGEMENT_SUBNET are themselves single global env vars,
# not per-NAS fragments the way addresses/peers/routes are — there is
# only ever ONE NAS's management subnet configured system-wide at a
# time by design (see those vars' own "one subnet only, static/manual"
# docblocks above), so a single pinned gateway is CORRECT as long as it
# points at the SAME NAS those subnet vars describe (true today: both
# are configured for test-x86-bajastu). This remains a real, documented
# generalization gap for the day a SECOND NAS gets its own TR069/OLT
# management subnet configured — fixing that properly needs actual
# per-NAS `nas.tr069_management_subnet`/`nas.olt_management_subnet`
# fragments (a genuinely new mechanism, not something to retrofit as a
# side effect of the FreeRADIUS fix) — not built here, same class of
# "single NAS only, documented, not yet generalized" limitation as
# everywhere else in this file. Falls back to plain MASQUERADE when
# WG_NAS_GATEWAY_IP is unset, same transitional-safety-net reasoning as
# before.
REVERSE_NAT_TARGET=(-j MASQUERADE)
if [ -n "$WG_NAS_GATEWAY_IP" ]; then
    REVERSE_NAT_TARGET=(-j SNAT --to-source "$WG_NAS_GATEWAY_IP")
fi

if [ -n "$TR069_MANAGEMENT_SUBNET" ]; then
    # Masquerade genieacs's real boss-network IP into this node's own
    # tun-side identity — the NAS never needs a route back to
    # boss-network's 172.28.0.0/24 (which would leak internal topology),
    # only to this node's own already-known wg0 gateway.
    iptables -t nat -A POSTROUTING -o wg0 -d "$TR069_MANAGEMENT_SUBNET" "${REVERSE_NAT_TARGET[@]}"
fi

# v0.8.1 — same MASQUERADE reasoning as the TR069 block above, applied to
# the OLT management subnet: librenms/librenms-dispatcher's real
# boss-network IPs get rewritten to this node's own wg0 gateway address
# before crossing the tunnel, so the NAS never needs (and is never told)
# a route back into 172.28.0.0/24.
if [ -n "$OLT_MANAGEMENT_SUBNET" ]; then
    iptables -t nat -A POSTROUTING -o wg0 -d "$OLT_MANAGEMENT_SUBNET" "${REVERSE_NAT_TARGET[@]}"
fi

echo ">> [entrypoint] wg0 up (pubkey $(cat "$WG_DIR/server_public.key")). Entering reconcile loop."

# Reconcile loop (same polling-restart idiom as boss-scheduler/
# boss-whatsapp-worker): `wg set`/netlink peer changes only affect THIS
# container's own network namespace, so boss-app (a separate namespace)
# can't apply them directly the way it runs `easyrsa` in-place against a
# shared PKI. Instead boss-app writes one [Peer] fragment per NAS to
# $PEERS_DIR, and this loop merges them into a full config and applies it
# with `wg syncconf`, which reconciles WITHOUT disrupting peers that didn't
# change (unlike `wg setconf`, which would replace the whole peer set).
#
# v0.6.4 health-check: piggybacks on this SAME loop rather than a separate
# background job — writes this node's own timestamp to the shared volume
# every cycle, one file per sibling node (named after NODE_HOSTNAME) so
# VpnCheckNodeHealth (boss-app, reading the same shared volume) can tell
# node1/node2/node3 apart. Same "communicate via the shared volume you
# already have" reasoning as openvpn/entrypoint.sh's own heartbeat.
while true; do
    # Real 500 found live (Langkah 3c browser testing, "Cabut & Generate
    # Ulang"): file_put_contents(/vpn-wg-data/addresses/nas-1.conf):
    # Permission denied. Root cause: the one-time boot chmod above (line
    # 92) only runs once, at container start — a file written AFTER that
    # by a root-run `docker compose exec` session (no --user flag, uid 0
    # by default; e.g. this same incident's own recovery tinker call)
    # lands root:root 0644, which a real www-data web request can then
    # never overwrite again. Exact same bug class already fixed this way
    # for docker/freeradius/entrypoint.sh's freeradius_nas_config (v0.6.5)
    # and VpnProvisioningService's own OpenVPN PKI chmod-after-every-call
    # fix (v0.6.3) — re-applying here every ~10s cycle instead of only at
    # boot self-heals a stray root-owned fragment quickly instead of
    # wedging the next real save permanently.
    chmod -R 0777 "$PEERS_DIR" "$ADDRESSES_DIR" 2>/dev/null || true

    {
        echo "[Interface]"
        echo "PrivateKey = ${SERVER_PRIVATE_KEY}"
        echo "ListenPort = ${WG_LISTEN_PORT}"
        echo
        cat "$PEERS_DIR"/*.conf 2>/dev/null || true
    } > /tmp/wg0-full.conf

    wg syncconf wg0 <(wg-quick strip /tmp/wg0-full.conf) 2>&1 || echo ">> [reconcile] syncconf failed, will retry next cycle"

    # v0.8.1 — apply every known NAS's own /30 gateway address to wg0,
    # same "one fragment file per NAS, reconciled every cycle on all 3
    # nodes" pattern as the peers above, just NOT via `wg syncconf` (that
    # command only understands WireGuard's own [Interface]/[Peer] config
    # syntax, not `ip address`). `ip address add` is NOT idempotent on its
    # own (errors "File exists" on a duplicate) — checked against the
    # interface's current addresses first, one exact "IP/mask" token
    # match via `grep -qw`, so a value already applied in an earlier cycle
    # is skipped rather than re-added every ~10s.
    #
    # v0.8.4 — SAME loop also reconciles one per-NAS reverse-NAT rule for
    # traffic destined to FreeRADIUS, fixing a real production bug: the
    # old single static rule (`-s $WG_SUBNET_CIDR -j SNAT --to-source
    # $WG_NAS_GATEWAY_IP`, applied ONCE at container start, removed above)
    # matched the WHOLE /24 — every NAS's block, not just the one
    # WG_NAS_GATEWAY_IP was actually pinned for — so a SECOND NAS's own
    # traffic toward FreeRADIUS got its source rewritten to the FIRST
    # NAS's gateway address instead of its own, and FreeRADIUS's reply
    # then went to the wrong address and was silently lost. Confirmed for
    # real via packet capture on a live NAS (see CLAUDE.md's own account
    # of this incident) before writing the FIRST version of this fix.
    #
    # AMENDMENT — the first version of this fix (SNAT --to-source
    # $gw_ip, i.e. this NAS's own wg0-side gateway address, e.g.
    # 172.23.195.5) was ITSELF wrong, in a way ping could never catch:
    # FreeRADIUS's per-NAS `clients {}` block (docker/freeradius's own
    # FreeradiusVirtualServerService-generated config) is scoped to
    # `ipaddr = 172.28.0.0/24` — boss-network — NOT the 172.23.195.0/24
    # WireGuard tunnel subnet. SNAT-ing to a 172.23.195.x address made
    # every real RADIUS request arrive as an "unknown client" to
    # FreeRADIUS, which silently drops it (confirmed verbatim in
    # /opt/var/log/radius/radius.log: "Ignoring request ... from unknown
    # client 172.23.195.5 ... proto udp") — indistinguishable from a
    # network-level timeout from the NAS's own point of view, which is
    # exactly why the "verified for real" ping test in the first version
    # of this fix never caught it: ICMP has no client-ACL concept at all,
    # so it happily succeeded through a rule that real RADIUS UDP traffic
    # could never actually pass. Fixed by switching to plain MASQUERADE
    # instead of SNAT to a specific address — MASQUERADE rewrites the
    # source to whatever address THIS node's own outgoing interface
    # (eth0, boss-network) actually has, which is always inside
    # 172.28.0.0/24 by construction, on every node, with no per-node
    # config needed. (The OTHER masquerade rules in this file —
    # TR069_MANAGEMENT_SUBNET/OLT_MANAGEMENT_SUBNET — are NOT the same
    # bug and were correctly left alone: those rewrite traffic in the
    # OPPOSITE direction, a boss-network service reaching OUT into a
    # NAS's own LAN, where the NAS's `allowed-address`/firewall instead
    # needs to see a trusted wg0-side address — 172.23.195.x is the
    # CORRECT target there, not a mistake to copy from.)
    #
    # Each $ADDRESSES_DIR/*.conf fragment is still "gateway_ip/30" — only
    # the `-s` match (this NAS's own /30 block, iptables network-aligns a
    # host+mask automatically) is used now, not the address itself as a
    # rewrite target.
    #
    # v0.8.4 amendment — widened from "-d $FREERADIUS_INTERNAL_IP" to
    # "-d $INFRA_TUNNEL_BLOCK_CIDR" (whole reserved /27), renamed
    # boss-vpn-snat-freeradius-* -> boss-vpn-snat-infra-block-* to match
    # (sweep regex below updated too). Real bug found deploying the
    # rsyslog receiver (172.28.0.230): the FORWARD rule above was widened
    # to the whole block, but this POSTROUTING rule was NOT — a router-
    # initiated packet toward .230 correctly passed the FORWARD filter
    # but kept its tunnel-side source (172.23.195.x), which
    # rsyslog-receiver (an ordinary boss-network container with no route
    # back into the WireGuard tunnel subnet) could never reply to.
    # Confirmed via a real `/ping` from the NAS: 100% loss to .230 while
    # the SAME test to .225 (FreeRADIUS, still correctly masqueraded at
    # the time) got real replies — proof this specific rule, not routing
    # or the FORWARD chain, was the gap. Widening it here closes the same
    # "future module needs zero entrypoint.sh change" gap the FORWARD
    # rule's own v0.8.4 widening was meant to close in the first place —
    # this rule was the missed half of that fix.
    #
    # Idempotency via `iptables -C` (the standard idiomatic check-before-
    # add for iptables, mirroring the ip-address grep check above, which
    # can't be reused as-is since NAT rules aren't listed by `ip addr`).
    # A `-m comment` tag (unique per NAS id, parsed straight from the
    # fragment's own filename) is what makes a rule identifiable enough
    # to check/delete later — plain `-s`/`-d`/`-j` alone would already be
    # unique per NAS in practice (no two NAS share a /30), but the
    # comment makes the intent self-documenting to anyone reading
    # `iptables -t nat -L POSTROUTING -n -v` directly on a live node.
    declare -A active_snat_nas=()
    for addr_file in "$ADDRESSES_DIR"/*.conf; do
        [ -e "$addr_file" ] || continue

        nas_id=$(basename "$addr_file" .conf)
        addr=$(tr -d '[:space:]' < "$addr_file")

        if [ -n "$addr" ] && ! ip -4 address show dev wg0 | grep -qw "$addr"; then
            ip address add "$addr" dev wg0 2>&1 || echo ">> [reconcile] failed to add address $addr from $addr_file, will retry next cycle"
        fi

        if [ -n "$addr" ]; then
            active_snat_nas["$nas_id"]=1

            if ! iptables -t nat -C POSTROUTING -s "$addr" -d "$INFRA_TUNNEL_BLOCK_CIDR" -j MASQUERADE -m comment --comment "boss-vpn-snat-infra-block-${nas_id}" 2>/dev/null; then
                iptables -t nat -A POSTROUTING -s "$addr" -d "$INFRA_TUNNEL_BLOCK_CIDR" -j MASQUERADE -m comment --comment "boss-vpn-snat-infra-block-${nas_id}" \
                    2>&1 || echo ">> [reconcile] failed to add reverse-NAT rule for ${nas_id}, will retry next cycle"
            fi
        fi
    done

    # Sweep per-NAS SNAT rules whose address fragment no longer exists
    # (NAS revoked) — deleted by rule NUMBER (re-queried fresh before
    # each single delete, since removing a rule shifts every later line
    # number), not by a partial `-D <criteria>` spec, which iptables
    # only matches against a rule's FULL original specification, not a
    # comment alone.
    for stale_nas in $(iptables -t nat -L POSTROUTING -n --line-numbers 2>/dev/null | grep -oE 'boss-vpn-snat-infra-block-nas-[0-9]+' | sed 's/boss-vpn-snat-infra-block-//' | sort -u); do
        if [ -z "${active_snat_nas[$stale_nas]:-}" ]; then
            line_no=$(iptables -t nat -L POSTROUTING -n --line-numbers 2>/dev/null | grep "boss-vpn-snat-infra-block-${stale_nas}\b" | head -1 | awk '{print $1}')
            [ -n "$line_no" ] && { iptables -t nat -D POSTROUTING "$line_no" 2>/dev/null || true; }
        fi
    done

    # v0.8.4 — write this node's own `wg show wg0 dump` (tab-delimited,
    # machine-parseable — NOT the pretty-printed default `wg show` output)
    # to the shared vpn_wg_data volume, which App\Console\Commands\
    # VpnSyncRouteFragments (boss-app) already mounts read-only-in-practice
    # at /vpn-wg-data. This REPLACES that command's old mechanism of
    # logging into the NAS's own router via RouterOS API just to ask
    # "which of the 3 pool nodes is your tunnel currently on" — that
    # question is fully answerable from THIS side instead (this node
    # already knows, from its own live wg0 state, which NAS public keys it
    # currently has a handshake with) — see CLAUDE.md's "Router API Login
    # Removal" section for the full investigation/reasoning. Confirmed
    # this is genuinely necessary and not something `boss-app` could do
    # unprompted: `boss-app` has no Docker exec access into this container
    # (deliberate stance since v0.6.2) and no wg0 interface of its own —
    # a file write from the SAME container that owns the interface is the
    # only path, same idiom as the `heartbeat-*` file immediately below.
    #
    # Line 1 of `wg show dump` is the INTERFACE line
    # (private-key\tpublic-key\tlisten-port\tfwmark) — field 1 (this
    # node's own private WireGuard key) is deliberately replaced with the
    # literal string REDACTED before writing. Not fixing a real
    # vulnerability (this exact same private key already sits in this
    # same shared volume as `server_private.key`, readable by boss-app
    # today, a pre-existing accepted posture — see wg_peers_dir's own
    # docblock in config/services.php) — just no reason to needlessly
    # duplicate a secret into a second file when the reader
    # (VpnSyncRouteFragments) never needs anything from that field beyond
    # column 3 (listen-port). Every subsequent line is one PEER
    # (public-key\tpreshared-key\tendpoint\tallowed-ips\tlatest-handshake
    # \ttransfer-rx\ttransfer-tx\tpersistent-keepalive) — VpnSyncRouteFragments
    # only reads columns 1 (public-key, matched against
    # vpn_accounts.public_key) and 5 (latest-handshake, a Unix timestamp,
    # 0 meaning "never handshaked").
    #
    # Atomic write (tmp file + `mv`, same idiom already used for the
    # per-NAS address/route fragments elsewhere in this script) — a
    # reader on the boss-app side must never observe a partially-written
    # file mid-write.
    wg show wg0 dump 2>/dev/null | awk 'BEGIN{FS=OFS="\t"} NR==1{$1="REDACTED"} {print}' > "$WG_DIR/wg-status-${NODE_HOSTNAME}.tmp" \
        && mv "$WG_DIR/wg-status-${NODE_HOSTNAME}.tmp" "$WG_DIR/wg-status-${NODE_HOSTNAME}"

    date +%s > "$WG_DIR/heartbeat-${NODE_HOSTNAME}"

    sleep 10
done
