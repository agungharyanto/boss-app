#!/bin/sh
set -e

# v0.7.3 — Connection Request routing. genieacs-cwmp/genieacs-nbi are
# separate containers from any VPN pool node (different network namespace
# entirely, only sharing boss-network), so reaching a NAS's TR-069
# management subnet through a WireGuard tunnel needs an explicit route
# pointing at whichever node currently holds that NAS's account. Static,
# manually-updated only — NOT re-derived automatically if the account
# later moves to a different pool node (no dynamic sync yet, same
# limitation as the firewall exception in docker/wireguard/entrypoint.sh —
# see CLAUDE.md "GenieACS Vendor Parameter Mapping").
if [ -n "${TR069_MANAGEMENT_SUBNET:-}" ] && [ -n "${TR069_MANAGEMENT_GATEWAY:-}" ]; then
    echo ">> [entrypoint] Adding route: ${TR069_MANAGEMENT_SUBNET} via ${TR069_MANAGEMENT_GATEWAY}"
    ip route replace "$TR069_MANAGEMENT_SUBNET" via "$TR069_MANAGEMENT_GATEWAY"
fi

exec "$@"
