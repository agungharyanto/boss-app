#!/bin/bash
set -euo pipefail

# A subdirectory of the mounted volume, NOT the mount root itself —
# `easyrsa init-pki` rm -rf's --pki-dir on first run, which fails with
# "Resource busy" if --pki-dir IS a Docker volume's own mountpoint.
PKI_DIR=/etc/openvpn/pki-data/pki
CCD_DIR=/etc/openvpn/ccd

: "${FREERADIUS_INTERNAL_IP:?FREERADIUS_INTERNAL_IP must be set}"
: "${VPN_SUBNET_CIDR:?VPN_SUBNET_CIDR must be set}"
: "${VPN_SUBNET_NETWORK:?VPN_SUBNET_NETWORK must be set}"
: "${VPN_SUBNET_NETMASK:?VPN_SUBNET_NETMASK must be set}"
: "${NODE_HOSTNAME:?NODE_HOSTNAME must be set}"

mkdir -p "$CCD_DIR"

# First-boot only — $PKI_DIR is the shared named volume also mounted into
# boss-app (VpnProvisioningService issues/revokes CLIENT certs against this
# same PKI later; this block only ever runs once, to create the CA + this
# node's own SERVER identity). EC certs (secp384r1) generate almost
# instantly, unlike RSA/DH — avoids the classic "openvpn container takes
# 5 minutes to boot the first time" problem.
#
# v0.6.4: this volume is now ALSO shared with sibling nodes (openvpn-
# node2/node3, see docker-compose.yml) — every node presents the SAME
# "server" cert/CA, which is fine since the generated Mikrotik client
# script already sets verify-server-certificate=no (identity isn't
# validated client-side). flock guards the genuinely-once bootstrap
# against a first-boot race between sibling containers — same reasoning
# as wireguard/entrypoint.sh's keygen lock.
(
    flock -x 200
    if [ ! -f "$PKI_DIR/ca.crt" ]; then
        echo ">> [entrypoint] Bootstrapping PKI (first boot for this volume)..."

        export EASYRSA_ALGO=ec
        export EASYRSA_CURVE=secp384r1
        export EASYRSA_BATCH=1

        easyrsa --pki-dir="$PKI_DIR" init-pki
        easyrsa --pki-dir="$PKI_DIR" build-ca nopass
        easyrsa --pki-dir="$PKI_DIR" build-server-full server nopass
        easyrsa --pki-dir="$PKI_DIR" gen-crl
        openvpn --genkey secret "$PKI_DIR/ta.key"

        echo ">> [entrypoint] PKI bootstrap done."
    fi
) 200>"$PKI_DIR/../.keygen.lock"

# The PKI volume is shared with boss-app (a completely separate container,
# different UID for its php-fpm workers — www-data) which needs to write
# new client certs into pki/issued, pki/private, pki/reqs and update
# pki/index.txt/serial. Chmod'ing the whole shared volume permissively is
# the pragmatic call here: only these two trusted containers we control
# ever mount it, nothing external. See CLAUDE.md "VPN Server Node #1
# (v0.6.2)" for the full rationale.
chmod -R 0777 "$PKI_DIR" "$CCD_DIR"

# --- Hub-and-spoke isolation (v0.6.2 locked decision) ---
# FREERADIUS_INTERNAL_IP is the static boss-network IP pinned to the
# freeradius service (see docker-compose.yml's boss-network IPAM config).
# "Don't push a route to it" alone is NOT isolation — every other
# container on boss-network (boss-postgresql, boss-redis, ...) is still
# reachable by IP unless this container's own FORWARD chain default-denies
# everything except the one destination NAS clients are allowed to reach.
iptables -F FORWARD
iptables -P FORWARD DROP
iptables -A FORWARD -i tun0 -d "$FREERADIUS_INTERNAL_IP" -j ACCEPT
iptables -A FORWARD -o tun0 -d "$VPN_SUBNET_CIDR" -m state --state ESTABLISHED,RELATED -j ACCEPT

# v0.6.5 CoA/Disconnect — one narrow, deliberate exception to the
# one-directional guarantee above (confirmed explicitly with Agung before
# implementing, since it changes a security-relevant boundary locked in at
# v0.6.2/v0.6.3): allows NEW connections initiated FROM FreeRADIUS's own
# static IP, OUT through this tunnel, to reach a NAS's internal_ip — the
# opposite direction of the existing NAS-initiated auth/acct traffic.
# Source is restricted to FREERADIUS_INTERNAL_IP specifically (not
# boss-network generally) because that's the ONLY address a NAS's own
# `/radius incoming` will accept a CoA/Disconnect-Request from — it's
# already the address every NAS's `/radius add` entry is configured with
# (see MikrotikScriptGenerator::radiusScript()), so CoaService's packets
# need no NAT/MASQUERADE to "look like" anything: freeradius's own real IP
# already IS that address. No destination restriction (-d) — a NAS's
# internal_ip varies (VpnAccount.internal_ip), always somewhere inside
# this tunnel's own subnet, already scoped by `-o tun0`.
iptables -A FORWARD -i eth0 -o tun0 -s "$FREERADIUS_INTERNAL_IP" -j ACCEPT

# MASQUERADE traffic leaving the VPN subnet toward FreeRADIUS so it looks
# like it came from this container's own boss-network IP — FreeRADIUS
# replies naturally without needing any route back to 172.23.194.0/24 at
# all (the actual "concentrator/relay" behavior the sprint asked for).
iptables -t nat -F POSTROUTING
iptables -t nat -A POSTROUTING -s "$VPN_SUBNET_CIDR" -d "$FREERADIUS_INTERNAL_IP" -j MASQUERADE

sed \
    -e "s|__VPN_SUBNET_NETWORK__|${VPN_SUBNET_NETWORK}|g" \
    -e "s|__VPN_SUBNET_NETMASK__|${VPN_SUBNET_NETMASK}|g" \
    -e "s|__FREERADIUS_IP__|${FREERADIUS_INTERNAL_IP}|g" \
    /etc/openvpn/server.conf.template > /etc/openvpn/server.conf

# v0.6.4 health-check: boss-app has no Docker socket access (deliberate
# stance since v0.6.2 — see CLAUDE.md), so "is this node's container alive"
# can't be a docker inspect/exec call. Instead this node writes its own
# timestamp to the SAME shared vpn_pki volume boss-app already mounts, one
# file per sibling node (named after NODE_HOSTNAME so VpnCheckNodeHealth can
# tell node1/node2/node3 apart) — same "communicate via the shared volume
# you already have" pattern as chap-secrets/wg peers, not a new network
# surface. Lives at the mount ROOT (a sibling of pki/), never inside pki/
# itself, to stay clear of easyrsa's own permission-sensitive files.
(
    while true; do
        date +%s > "$PKI_DIR/../heartbeat-${NODE_HOSTNAME}"
        sleep 10
    done
) &

exec openvpn --config /etc/openvpn/server.conf
