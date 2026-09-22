#!/bin/sh
set -e

# olt-sidecar (v0.23.3) — consumer ke-6 di pola fragment+reconcile
# WireGuard yang sudah ada (lihat docs/omci/sidecar-design.md §3 Opsi A,
# dan CLAUDE.md "Fragment+Reconcile Routing (v0.8.1)"). App\Console\
# Commands\VpnSyncRouteFragments (boss-app, ->everyMinute()) SUDAH menulis
# baris rute subnet manajemen OLT ({oltSubnet} via {nodeIp}) untuk setiap
# NAS yang punya OltDevice terdaftar — sidecar cukup MEMBACA fragment yang
# SAMA (volume vpn_wg_data, read-only mount), tidak perlu perubahan apa
# pun di sisi command Laravel itu sendiri, dan tidak ada perubahan sisi
# NAS (AllowedIPs/firewall NAS sudah mempercayai seluruh
# INFRA_TUNNEL_BLOCK_CIDR sebagai satu blok sejak v0.8.1).
#
# Pola persis docker/librenms/route-init.sh / docker/genieacs/entrypoint.sh
# — bukan mekanisme baru, direplikasi apa adanya untuk consumer ke-6 ini.
ROUTES_DIR="${VPN_ROUTES_DIR:-/vpn-wg-data/routes}"

(
    while true; do
        for route_file in "$ROUTES_DIR"/*.conf; do
            [ -e "$route_file" ] || continue

            while IFS= read -r line; do
                [ -n "$line" ] || continue
                subnet=$(echo "$line" | awk '{print $1}')
                gateway=$(echo "$line" | awk '{print $3}')
                [ -n "$subnet" ] && [ -n "$gateway" ] && ip route replace "$subnet" via "$gateway" 2>/dev/null
            done < "$route_file"
        done

        sleep 5
    done
) &

exec "$@"
