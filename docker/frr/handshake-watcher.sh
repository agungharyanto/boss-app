#!/bin/sh
set -u

# BOSS App — v0.8.1 OSPF, NODE-role sidecars only (wireguard/
# wireguard-node2/wireguard-node3). Decides which prefixes THIS node is
# allowed to announce into OSPF (via FRR static routes, picked up by
# `redistribute static route-map OSPF-WG-ONLY` in
# frr.conf.node.template) — conditional on a genuinely LIVE WireGuard
# tunnel for that specific NAS on THIS node.
#
# HYBRID liveness check (Agung's explicit decision, after Tahap A found
# the original handshake-age-only design flapped constantly on a
# perfectly healthy tunnel) — a route is kept/installed if EITHER:
#   1. `latest-handshakes` age < HANDSHAKE_FRESH_THRESHOLD_SECONDS (150s
#      — WireGuard's own Noise-protocol rekey is ~120s by default;
#      persistent-keepalive (25s) does NOT trigger a rekey, it only keeps
#      the NAT mapping open, so a healthy tunnel's handshake age routinely
#      exceeds 30s — confirmed empirically in Tahap A: a real live tunnel
#      sat at 51s-115s+ handshake age while rx/tx kept climbing the whole
#      time), OR
#   2. rx byte counter increased since the LAST watcher cycle (5s ago) —
#      catches an actively-passing-traffic tunnel within one cycle
#      regardless of handshake age; this is expected to be true almost
#      always for a healthy tunnel (keepalive alone bumps rx every ~25s,
#      well within any 5s-cycle comparison window eventually).
# The route is only WITHDRAWN when BOTH fail — realistically only when
# the tunnel has genuinely gone dead or the NAS failed over to a
# different node entirely.
#
# Redistributes EVERY prefix in a peer's own AllowedIPs line — e.g.
# "172.23.195.2/32, 10.1.0.0/20, 10.168.100.0/24" — not just the /30
# router address. Real gap found deploying Tahap B (librenms-dispatcher):
# an earlier version only advertised the router /32, leaving the actual
# TR-069/OLT management subnets behind a NAS never redistributed at all.
#
# Real bug found and fixed getting THAT far: these subnet prefixes used
# to also exist as a plain KERNEL route (`K` in `show ip route`, NOT `C`/
# connected) installed by a bash-level `ip route replace ... dev wg0` in
# docker/wireguard/entrypoint.sh (the old, now-removed, v0.7.3-era static
# mechanism) — a zebra static route for the exact same prefix always
# loses FIB selection to that kernel route (confirmed directly: `show ip
# ospf database external` stayed empty for these prefixes the whole time
# that old line was still present, no matter what redistribute/route-map
# combination was tried, since `redistribute static` only ever announces
# the RIB-selected/winning route per prefix). `redistribute connected`
# was also tried and also failed — for a DIFFERENT reason: that kernel
# route's type is `K`, not `C`, so zebra never even considers it eligible
# for `connected` redistribution regardless of route-map filtering. The
# actual fix was removing the old bash-level route entirely (see
# docker/wireguard/entrypoint.sh's own comment on this) — once that's
# gone, THIS script's own zebra-native static route becomes the only
# entry for the prefix, wins selection cleanly, and redistributes fine.
# With that fixed, every AllowedIPs prefix — /32 router address and
# management subnets alike — uses the exact same static+tag mechanism,
# no special-casing needed.
#
# Tag-based route-map match (`tag 100`, see frr.conf.node.template),
# NOT a prefix-list keyed to a fixed numeric range — a customer's real
# TR-069/OLT management LAN can be any private range an ISP happens to
# use, so a hardcoded prefix-list boundary (only ever correct for the one
# NAS tested so far) would silently exclude a future NAS's differently-
# ranged subnet. Tagging ties the OSPF-WG-ONLY permission to "was this
# route actually created by this mechanism", which stays correct
# regardless of what ranges any future NAS's AllowedIPs happens to
# contain.
#
# `ip route` is naturally idempotent in FRR (re-adding an identical
# static route just no-ops) — confirmed directly, unlike `ip prefix-list
# ... permit ...` (tried and abandoned for the subnet case above), which
# silently creates a brand-new duplicate numbered entry every single
# time even for byte-identical content.

WG_DIR=/etc/wireguard
PEERS_DIR="$WG_DIR/peers"
HANDSHAKE_THRESHOLD="${HANDSHAKE_FRESH_THRESHOLD_SECONDS:-150}"
STATE_DIR=/tmp/watcher-state
ROUTE_TAG=100

mkdir -p "$STATE_DIR"

echo ">> [handshake-watcher] starting, handshake_threshold=${HANDSHAKE_THRESHOLD}s, byte-delta hybrid enabled, cycle=5s"

while true; do
    handshakes=$(wg show wg0 latest-handshakes 2>/dev/null || true)
    transfer=$(wg show wg0 transfer 2>/dev/null || true)
    now=$(date +%s)

    vtysh_cmds="conf t"

    for peer_file in "$PEERS_DIR"/*.conf; do
        [ -e "$peer_file" ] || continue

        username=$(basename "$peer_file" .conf)
        pubkey=$(grep '^PublicKey' "$peer_file" | awk '{print $3}')
        [ -n "$pubkey" ] || continue

        # Every prefix in this peer's AllowedIPs line — comma+space
        # separated, matching exactly how VpnProvisioningService writes it.
        allowed_ips_line=$(grep '^AllowedIPs' "$peer_file" | sed 's/^AllowedIPs = //')
        [ -n "$allowed_ips_line" ] || continue

        # Condition 1: handshake freshness.
        ts=$(echo "$handshakes" | awk -v pk="$pubkey" '$1 == pk {print $2}')
        ts="${ts:-0}"
        age=$((now - ts))
        handshake_fresh=false
        [ "$ts" != "0" ] && [ "$age" -lt "$HANDSHAKE_THRESHOLD" ] && handshake_fresh=true

        # Condition 2: rx byte counter increased since last cycle. First
        # ever cycle for this peer (no prior state file) has nothing to
        # compare against — default to "alive" for that one cycle rather
        # than risk a spurious withdrawal before two samples exist.
        rx_now=$(echo "$transfer" | awk -v pk="$pubkey" '$1 == pk {print $2}')
        rx_now="${rx_now:-0}"
        state_file="$STATE_DIR/${username}.rx"
        traffic_active=true
        if [ -f "$state_file" ]; then
            rx_prev=$(cat "$state_file" 2>/dev/null || echo 0)
            if [ "$rx_now" -le "$rx_prev" ]; then
                traffic_active=false
            fi
        fi
        echo "$rx_now" > "$state_file"

        peer_alive=false
        [ "$handshake_fresh" = "true" ] || [ "$traffic_active" = "true" ] && peer_alive=true

        old_ifs="$IFS"
        IFS=,
        for prefix in $allowed_ips_line; do
            prefix=$(echo "$prefix" | tr -d '[:space:]')
            [ -n "$prefix" ] || continue

            if [ "$peer_alive" = "true" ]; then
                vtysh_cmds="$vtysh_cmds
ip route ${prefix} wg0 tag ${ROUTE_TAG}"
            else
                vtysh_cmds="$vtysh_cmds
no ip route ${prefix} wg0"
            fi
        done
        IFS="$old_ifs"
    done

    vtysh_cmds="$vtysh_cmds
end"

    printf '%s\n' "$vtysh_cmds" | vtysh >/dev/null 2>&1 || echo ">> [handshake-watcher] vtysh apply failed, will retry next cycle"

    sleep 5
done
