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
iptables -A FORWARD -i wg0 -d "$FREERADIUS_INTERNAL_IP" -j ACCEPT
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
# Pinned to WG_NAS_GATEWAY_IP — the ONE NAS this reverse-masquerade path
# is currently configured for (same "one NAS only" limitation
# TR069_MANAGEMENT_SUBNET/OLT_MANAGEMENT_SUBNET already have). Falls back
# to plain MASQUERADE (old behavior) when WG_NAS_GATEWAY_IP is unset, so a
# deployment that hasn't set the new var yet doesn't break — but that
# fallback is exactly as ambiguous as described above the moment more
# than one NAS block exists on this node, so it's a transitional safety
# net, not a real fix for the general case.
#
# GENERALIZATION GAP, not silently hardcoded without a trace: a real
# multi-NAS-aware version needs to match each packet's DESTINATION subnet
# to the correct NAS's own gateway (not one global pinned value) — not
# built here, same class of "single NAS only, documented, not yet
# generalized" limitation as everywhere else in this file.
REVERSE_NAT_TARGET=(-j MASQUERADE)
if [ -n "$WG_NAS_GATEWAY_IP" ]; then
    REVERSE_NAT_TARGET=(-j SNAT --to-source "$WG_NAS_GATEWAY_IP")
fi

iptables -t nat -A POSTROUTING -s "$WG_SUBNET_CIDR" -d "$FREERADIUS_INTERNAL_IP" "${REVERSE_NAT_TARGET[@]}"

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
    for addr_file in "$ADDRESSES_DIR"/*.conf; do
        [ -e "$addr_file" ] || continue

        addr=$(tr -d '[:space:]' < "$addr_file")

        if [ -n "$addr" ] && ! ip -4 address show dev wg0 | grep -qw "$addr"; then
            ip address add "$addr" dev wg0 2>&1 || echo ">> [reconcile] failed to add address $addr from $addr_file, will retry next cycle"
        fi
    done

    date +%s > "$WG_DIR/heartbeat-${NODE_HOSTNAME}"

    sleep 10
done
