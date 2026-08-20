#!/bin/bash
set -euo pipefail

WG_DIR=/etc/wireguard
PEERS_DIR="$WG_DIR/peers"

: "${WG_LISTEN_PORT:?WG_LISTEN_PORT must be set}"
: "${WG_SUBNET_NETWORK_ADDR:?WG_SUBNET_NETWORK_ADDR must be set}" # e.g. 172.23.195.1
: "${WG_SUBNET_CIDR:?WG_SUBNET_CIDR must be set}"                  # e.g. 172.23.195.0/24
: "${FREERADIUS_INTERNAL_IP:?FREERADIUS_INTERNAL_IP must be set}"
: "${NODE_HOSTNAME:?NODE_HOSTNAME must be set}"
# v0.7.3 — Connection Request routing. Deliberately optional (unset/empty
# skips the exception below entirely) — not every deployment has a NAS with
# tr069_management_subnet configured yet. GENIEACS_CWMP_INTERNAL_IP/
# GENIEACS_NBI_INTERNAL_IP must be pinned static IPs (docker-compose.yml),
# same "must not silently drift on container recreation" reasoning as
# FREERADIUS_INTERNAL_IP.
TR069_MANAGEMENT_SUBNET="${TR069_MANAGEMENT_SUBNET:-}"
GENIEACS_CWMP_INTERNAL_IP="${GENIEACS_CWMP_INTERNAL_IP:-}"
GENIEACS_NBI_INTERNAL_IP="${GENIEACS_NBI_INTERNAL_IP:-}"

mkdir -p "$PEERS_DIR"

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
chmod -R 0777 "$PEERS_DIR"

SERVER_PRIVATE_KEY=$(cat "$WG_DIR/server_private.key")

# wg0 is network-namespace state, not volume state — always (re)created
# fresh on every container start. The `ip link del` guards against a plain
# `docker restart` (same container, same netns) where a previous run's wg0
# might still exist.
ip link del dev wg0 2>/dev/null || true
ip link add dev wg0 type wireguard
ip address add "${WG_SUBNET_NETWORK_ADDR}/24" dev wg0
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
    ip route replace "$TR069_MANAGEMENT_SUBNET" dev wg0

    if [ -n "$GENIEACS_CWMP_INTERNAL_IP" ]; then
        iptables -A FORWARD -i eth0 -o wg0 -s "$GENIEACS_CWMP_INTERNAL_IP" -d "$TR069_MANAGEMENT_SUBNET" -j ACCEPT
    fi
    if [ -n "$GENIEACS_NBI_INTERNAL_IP" ]; then
        iptables -A FORWARD -i eth0 -o wg0 -s "$GENIEACS_NBI_INTERNAL_IP" -d "$TR069_MANAGEMENT_SUBNET" -j ACCEPT
    fi
    iptables -A FORWARD -o eth0 -i wg0 -s "$TR069_MANAGEMENT_SUBNET" -m state --state ESTABLISHED,RELATED -j ACCEPT
fi

iptables -t nat -F POSTROUTING
iptables -t nat -A POSTROUTING -s "$WG_SUBNET_CIDR" -d "$FREERADIUS_INTERNAL_IP" -j MASQUERADE

if [ -n "$TR069_MANAGEMENT_SUBNET" ]; then
    # Masquerade genieacs's real boss-network IP into this node's own
    # tun-side identity — the NAS never needs a route back to
    # boss-network's 172.28.0.0/24 (which would leak internal topology),
    # only to this node's own already-known wg0 gateway.
    iptables -t nat -A POSTROUTING -o wg0 -d "$TR069_MANAGEMENT_SUBNET" -j MASQUERADE
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
    {
        echo "[Interface]"
        echo "PrivateKey = ${SERVER_PRIVATE_KEY}"
        echo "ListenPort = ${WG_LISTEN_PORT}"
        echo
        cat "$PEERS_DIR"/*.conf 2>/dev/null || true
    } > /tmp/wg0-full.conf

    wg syncconf wg0 <(wg-quick strip /tmp/wg0-full.conf) 2>&1 || echo ">> [reconcile] syncconf failed, will retry next cycle"

    date +%s > "$WG_DIR/heartbeat-${NODE_HOSTNAME}"

    sleep 10
done
