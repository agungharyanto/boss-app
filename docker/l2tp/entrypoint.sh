#!/bin/bash
set -euo pipefail

: "${VPN_PUBLIC_IP:?VPN_PUBLIC_IP must be set}"
: "${L2TP_IPSEC_PSK:?L2TP_IPSEC_PSK must be set}"
: "${L2TP_LOCAL_IP:?L2TP_LOCAL_IP must be set}"           # e.g. 172.23.196.1
: "${L2TP_IP_RANGE_START:?L2TP_IP_RANGE_START must be set}"
: "${L2TP_IP_RANGE_END:?L2TP_IP_RANGE_END must be set}"
: "${L2TP_SUBNET_CIDR:?L2TP_SUBNET_CIDR must be set}"      # e.g. 172.23.196.0/24
: "${FREERADIUS_INTERNAL_IP:?FREERADIUS_INTERNAL_IP must be set}"

sed "s|__PUBLIC_IP__|${VPN_PUBLIC_IP}|g" /etc/ipsec.conf.template > /etc/ipsec.conf

# %any %any — one PSK shared by the whole node (v0.6.3 decision: IPsec-layer
# auth is a single infra-level secret, NOT per-NAS; per-NAS identity/auth
# happens one layer up, at the PPP/chap-secrets level below).
echo "%any %any : PSK \"${L2TP_IPSEC_PSK}\"" > /etc/ipsec.secrets

sed \
    -e "s|__L2TP_IP_RANGE_START__|${L2TP_IP_RANGE_START}|g" \
    -e "s|__L2TP_IP_RANGE_END__|${L2TP_IP_RANGE_END}|g" \
    -e "s|__L2TP_LOCAL_IP__|${L2TP_LOCAL_IP}|g" \
    /etc/xl2tpd/xl2tpd.conf.template > /etc/xl2tpd/xl2tpd.conf

# /etc/ppp/secrets-data (the shared vpn_l2tp_secrets volume — a named
# Docker volume, mounted as a directory, so the actual file lives one level
# inside it, not at the mount root) holds chap-secrets. boss-app mounts the
# SAME volume at /vpn-l2tp-data and VpnProvisioningService REWRITES the
# whole file from the DB's active l2tp_ipsec vpn_accounts on every
# provision()/revoke() call (a full regenerate, not line-by-line
# append/remove — simpler, avoids partial-edit bugs). pppd is spawned fresh
# by xl2tpd per incoming call (not a long-running resident process caching
# this file in memory), so a rewritten file takes effect on the very next
# connection attempt with no reload/signal needed — same "read fresh per
# attempt" shape as FreeRADIUS's radcheck query and OpenVPN's crl-verify.
mkdir -p /etc/ppp/secrets-data
touch /etc/ppp/secrets-data/chap-secrets
chmod 0666 /etc/ppp/secrets-data/chap-secrets
ln -sf /etc/ppp/secrets-data/chap-secrets /etc/ppp/chap-secrets

# --- Hub-and-spoke isolation (same 3-layer intent as openvpn/wireguard) ---
# `ppp+` (not a single fixed interface name): xl2tpd/pppd creates ONE ppp*
# interface PER CONNECTED CLIENT (ppp0, ppp1, ...), unlike OpenVPN's single
# shared tun0 or WireGuard's single wg0 — iptables' `+` suffix wildcard-
# matches any interface starting with "ppp".
iptables -F FORWARD
iptables -P FORWARD DROP
iptables -A FORWARD -i ppp+ -d "$FREERADIUS_INTERNAL_IP" -j ACCEPT
iptables -A FORWARD -o ppp+ -d "$L2TP_SUBNET_CIDR" -m state --state ESTABLISHED,RELATED -j ACCEPT

iptables -t nat -F POSTROUTING
iptables -t nat -A POSTROUTING -s "$L2TP_SUBNET_CIDR" -d "$FREERADIUS_INTERNAL_IP" -j MASQUERADE

# xl2tpd/pppd/charon all log via syslog() by default — invisible in this
# minimal image with no syslog daemon, which made a real connect-then-
# disconnect bug (found during real RouterOS 7.11 testing) impossible to
# diagnose until this was added. busybox syslogd writes everything to one
# file any operator/debugger can read (docker compose exec ... cat).
syslogd -O /var/log/syslog.log -n &

echo ">> [entrypoint] Starting strongSwan (IPsec)..."
ipsec start

# Give charon a moment to come up before xl2tpd starts negotiating —
# avoids a cold-start race where the first real connection attempt arrives
# before IPsec is actually listening.
sleep 2

echo ">> [entrypoint] Starting xl2tpd in foreground..."
exec xl2tpd -D
