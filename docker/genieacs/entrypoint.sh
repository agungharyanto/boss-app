#!/bin/sh
set -e

# v0.8.1 fragment+reconcile (replaces the old TR069_MANAGEMENT_SUBNET/
# TR069_MANAGEMENT_GATEWAY static-route mechanism, and the OSPF experiment
# that briefly replaced THAT — see CLAUDE.md's "OSPF Dynamic Routing"/
# "Fragment+Reconcile Routing" sections). genieacs-cwmp/genieacs-nbi are
# separate containers from any VPN pool node (different network namespace
# entirely, only sharing boss-network), so reaching a NAS's TR-069
# management subnet through a WireGuard tunnel needs an explicit route
# pointing at whichever node currently holds that NAS's account.
# App\Console\Commands\VpnSyncRouteFragments (boss-app, scheduled
# ->everyMinute()) is now the source of truth for that — it writes one
# file per active WireGuard NAS to the shared vpn_wg_data volume
# ($ROUTES_DIR/nas-{id}.conf, "<subnet> via <node_ip>" per line, derived
# from asking the NAS's own router which node it's CURRENTLY connected to
# — see that command's own docblock). This loop just reads and applies
# every line found, every 5s — same polling-loop idiom already used for
# peer/address fragments in docker/wireguard/entrypoint.sh, no routing
# protocol, no extra daemon. Read-only (:ro) mount — this container never
# writes here, only boss-app does.
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
