#!/bin/bash
set -euo pipefail

WG_DIR=/etc/wireguard
PEERS_DIR="$WG_DIR/peers"

: "${WG_LISTEN_PORT:?WG_LISTEN_PORT must be set}"
: "${WG_SUBNET_NETWORK_ADDR:?WG_SUBNET_NETWORK_ADDR must be set}" # e.g. 172.23.195.1
: "${WG_SUBNET_CIDR:?WG_SUBNET_CIDR must be set}"                  # e.g. 172.23.195.0/24
: "${FREERADIUS_INTERNAL_IP:?FREERADIUS_INTERNAL_IP must be set}"

mkdir -p "$PEERS_DIR"

# Server keypair persists on the shared vpn_wg_data volume (also mounted
# into boss-app) — generated once, unlike wg0 itself which is re-created
# fresh every container start (network namespace state, not volume state).
if [ ! -f "$WG_DIR/server_private.key" ]; then
    echo ">> [entrypoint] Generating WireGuard server keypair (first boot for this volume)..."
    wg genkey | tee "$WG_DIR/server_private.key" | wg pubkey > "$WG_DIR/server_public.key"
fi

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

iptables -t nat -F POSTROUTING
iptables -t nat -A POSTROUTING -s "$WG_SUBNET_CIDR" -d "$FREERADIUS_INTERNAL_IP" -j MASQUERADE

echo ">> [entrypoint] wg0 up (pubkey $(cat "$WG_DIR/server_public.key")). Entering reconcile loop."

# Reconcile loop (same polling-restart idiom as boss-scheduler/
# boss-whatsapp-worker): `wg set`/netlink peer changes only affect THIS
# container's own network namespace, so boss-app (a separate namespace)
# can't apply them directly the way it runs `easyrsa` in-place against a
# shared PKI. Instead boss-app writes one [Peer] fragment per NAS to
# $PEERS_DIR, and this loop merges them into a full config and applies it
# with `wg syncconf`, which reconciles WITHOUT disrupting peers that didn't
# change (unlike `wg setconf`, which would replace the whole peer set).
while true; do
    {
        echo "[Interface]"
        echo "PrivateKey = ${SERVER_PRIVATE_KEY}"
        echo "ListenPort = ${WG_LISTEN_PORT}"
        echo
        cat "$PEERS_DIR"/*.conf 2>/dev/null || true
    } > /tmp/wg0-full.conf

    wg syncconf wg0 <(wg-quick strip /tmp/wg0-full.conf) 2>&1 || echo ">> [reconcile] syncconf failed, will retry next cycle"

    sleep 10
done
