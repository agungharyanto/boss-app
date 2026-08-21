#!/bin/sh
set -e

# v0.8.1 fragment+reconcile (replaces the old OLT_MANAGEMENT_SUBNET/
# OLT_MANAGEMENT_GATEWAY static-route mechanism, and the OSPF experiment
# that briefly replaced THAT — see CLAUDE.md's "OSPF Dynamic Routing"/
# "Fragment+Reconcile Routing" sections). librenms/librenms-dispatcher are
# official librenms/librenms:latest containers with NO custom Dockerfile
# of our own, so this script is bind-mounted in and set as their
# `entrypoint:` override in docker-compose.yml (wrapping, not replacing,
# the image's own /init) purely to run this reconcile loop before handing
# off. App\Console\Commands\VpnSyncRouteFragments (boss-app, scheduled
# ->everyMinute()) is now the source of truth for which node each
# WireGuard NAS's tunnel is CURRENTLY connected to — it writes one file
# per NAS to the shared vpn_wg_data volume ($ROUTES_DIR/nas-{id}.conf,
# "<subnet> via <node_ip>" per line, including the OLT management subnet
# for any NAS with an OLT actually registered — see that command's own
# docblock). This loop just reads and applies every line found, every
# 5s — same polling-loop idiom already used for peer/address fragments in
# docker/wireguard/entrypoint.sh, no routing protocol, no extra daemon.
# Read-only (:ro) mount — this container never writes here, only boss-app
# does.
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

exec /init
