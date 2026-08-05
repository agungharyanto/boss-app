#!/bin/sh
set -eu

# Base image's own /docker-entrypoint.sh just `exec radiusd -f` as PID 1
# (confirmed via `docker image inspect` + reading that script directly).
# This replaces it because v0.6.5's dynamic per-NAS virtual server needs
# something ALIVE alongside radiusd to supervise restarts — a plain `exec`
# leaves no room for that. PATH mirrors what the base entrypoint exported
# (not baked into the image's own ENV, verified by checking `$PATH` inside
# a running container before this change).
PATH=/opt/sbin:/opt/bin:$PATH
export PATH

NAS_CONFIG_DIR=/freeradius-nas-config
LISTEN_DIR="$NAS_CONFIG_DIR/listen"
CLIENTS_DIR="$NAS_CONFIG_DIR/clients"
COA_QUEUE_DIR="$NAS_CONFIG_DIR/coa-queue"

mkdir -p "$LISTEN_DIR" "$CLIENTS_DIR" "$COA_QUEUE_DIR"
# Shared with boss-app (php-fpm workers run as www-data, uid/gid 82) — NOT
# the permissive 0777 chmod used for vpn_pki/vpn_wg_data/vpn_l2tp_secrets
# (CLAUDE.md v0.6.2/3): found for real on first boot that FreeRADIUS
# actively REFUSES to start with a world-writable $INCLUDE'd directory
# ("Directory ... is globally writable. Refusing to start due to insecure
# configuration" — radiusd's own security hardening, not a bug). radiusd
# itself runs as root the whole time (no user=/group= drop in
# radiusd.conf), so group-write for gid 82 is enough for both sides:
# root (radiusd, reading) always has access regardless of group, www-data
# (writing from boss-app) is gid 82 itself.
chgrp -R 82 "$NAS_CONFIG_DIR"
chmod -R 0770 "$NAS_CONFIG_DIR"

# --- One-time, idempotent config patch (survives image rebuilds; grep -q
# guards re-running on every container restart) ---
#
# $INCLUDE of a DIRECTORY (not just a single file) is real, working
# FreeRADIUS syntax — verified for real against this exact image: a test
# listen{} dropped into a $INCLUDE'd directory, followed by a restart,
# actually opened the new UDP port (netstat before/after), and a radclient
# Access-Request through it round-tripped a real Access-Reject from rlm_sql
# (proving the per-socket `clients = ...` scoping works too, not just that
# the port opened). SIGHUP alone was ALSO tested first and does NOT open
# new listen sockets — module/virtual-server reload only, confirmed via the
# server's own log ("HUP - Re-reading configuration files" with zero
# mention of the new listener, and the port staying closed) — this is why
# this whole file exists instead of a simple `kill -HUP`.
if ! grep -q "$LISTEN_DIR" /etc/raddb/sites-enabled/default 2>/dev/null; then
    sed -i "/^#  Authorization\. First preprocess/i \\\$INCLUDE $LISTEN_DIR/\\n" /etc/raddb/sites-enabled/default
fi
if ! grep -q "$CLIENTS_DIR" /etc/raddb/clients.conf 2>/dev/null; then
    echo "\$INCLUDE $CLIENTS_DIR/" >> /etc/raddb/clients.conf
fi

# Real production incident (found debugging test-x-bajastu, v0.6.5
# amendment): a NAS's Accounting-Request traffic was previously stopped by
# pointing the ROUTER's own /radius accounting-port at a dead port (1) —
# this "worked" in the sense that we never collected the data, but every
# single accounting packet then genuinely timed out (no listener at all),
# which polluted /radius/monitor's counters with a 100% failure rate that
# briefly looked like a FreeRADIUS performance problem, and made the NAS
# retransmit forever for nothing. The correct fix is the other end of this:
# FreeRADIUS DOES listen on the real accounting port (see the per-NAS
# listen{} config above) and DOES answer every Accounting-Request with a
# real Accounting-Response — it just never persists what's in it. `detail`
# (raw packet dump to a logfile) and `-sql` (write to radacct) are both
# disabled below, so nothing customer-identifiable is written to disk or
# the database — same "don't collect data we don't need" posture already
# applied to radacct/detail files earlier this session. `exec` and
# `attr_filter.accounting_response` are left untouched (harmless — no
# Exec-Program configured, and attribute filtering doesn't persist
# anything). This patches the ONE shared `accounting {}` section in
# sites-enabled/default, so it applies to every NAS's accounting listener,
# not just this one — deliberate, since none of them should be logging raw
# customer accounting data without a real reason to.
if ! grep -q "boss-app: accounting logging disabled" /etc/raddb/sites-enabled/default 2>/dev/null; then
    sed -i '/^accounting {/,/^}/{
        s/^\tdetail$/#\tdetail\t# boss-app: accounting logging disabled (privacy - no raw packet log)/
        s/^\t-sql$/#\t-sql\t# boss-app: accounting logging disabled (privacy - do not persist radacct)/
    }' /etc/raddb/sites-enabled/default
fi

# v0.6.5 CoA/Disconnect — this container has no route to either VPN node's
# tunnel subnet by default (boss-network only knows about 172.28.0.0/24;
# 172.23.194.0/24 and 172.23.195.0/24 live inside openvpn's/wireguard's own
# additional tun0/wg0 interfaces). Route via NODE1 specifically (resolved
# by container NAME, not a hardcoded IP — docker-compose doesn't pin these
# containers' boss-network IPs the way freeradius's own is pinned, so a
# hardcoded IP would silently go stale across a recreate) — node1 is the
# POOL OWNER (VpnServer::poolOwnerFor()), the only node guaranteed to
# actually know about every account's internal_ip via OpenVPN's ccd/
# WireGuard's shared peers dir. A NAS currently failed-over (v0.6.4 auto-
# switch) to a SIBLING node is a known, documented gap — see
# docker/wireguard/entrypoint.sh's matching comment on the firewall side.
refresh_coa_routes() {
    openvpn_ip=$(getent hosts openvpn 2>/dev/null | awk '{print $1}')
    wireguard_ip=$(getent hosts wireguard 2>/dev/null | awk '{print $1}')
    [ -n "$openvpn_ip" ] && [ -n "${VPN_SUBNET_CIDR:-}" ] && ip route replace "$VPN_SUBNET_CIDR" via "$openvpn_ip" 2>/dev/null || true
    [ -n "$wireguard_ip" ] && [ -n "${WG_SUBNET_CIDR:-}" ] && ip route replace "$WG_SUBNET_CIDR" via "$wireguard_ip" 2>/dev/null || true
}

refresh_coa_routes

RADIUSD_PID=""

start_radiusd() {
    radiusd -f &
    RADIUSD_PID=$!
}

stop_radiusd() {
    if [ -n "$RADIUSD_PID" ] && kill -0 "$RADIUSD_PID" 2>/dev/null; then
        kill -TERM "$RADIUSD_PID"
        wait "$RADIUSD_PID" 2>/dev/null || true
    fi
}

# Forward container stop signals to the supervised child instead of dying
# and leaving radiusd orphaned/killed ungracefully.
trap 'stop_radiusd; exit 0' TERM INT

config_fingerprint() {
    # Detects ANY change (add/remove/edit) across both directories — no
    # generation-counter protocol needed between PHP and this shell loop,
    # deliberately (see FreeradiusVirtualServerService's own docblock).
    find "$LISTEN_DIR" "$CLIENTS_DIR" -type f -exec stat -c '%n %Y %s' {} \; 2>/dev/null | sort | md5sum
}

start_radiusd
last_fingerprint=$(config_fingerprint)

echo ">> [entrypoint] radiusd started (pid $RADIUSD_PID). Entering NAS config watch / CoA queue loop."

while true; do
    sleep 3

    # Self-heals a real, reproduced-for-real permission bug: any file
    # written as root inside this shared volume (e.g. `docker compose exec
    # boss-app php artisan tinker`, which always runs as root, unlike real
    # www-data-driven requests — same root cause already documented for
    # the OpenVPN PKI "nas-11" incident, CLAUDE.md v0.6.3) lands as
    # root:root 0644, which www-data can then never overwrite again — a
    # real NAS "Simpan" in the UI 500'd on exactly this ("Permission
    # denied") the first time this was tested end-to-end. Cheap enough
    # (a handful of small files) to just re-apply every cycle rather than
    # only at boot, so any future stray root-owned file self-heals within
    # a few seconds instead of wedging the next real save permanently.
    chgrp -R 82 "$NAS_CONFIG_DIR" 2>/dev/null || true
    chmod -R 0770 "$NAS_CONFIG_DIR" 2>/dev/null || true
    refresh_coa_routes

    current_fingerprint=$(config_fingerprint)
    if [ "$current_fingerprint" != "$last_fingerprint" ]; then
        echo ">> [entrypoint] nas-config change detected — restarting radiusd to bind new listen sockets."
        stop_radiusd
        start_radiusd
        last_fingerprint=$(config_fingerprint)
    elif ! kill -0 "$RADIUSD_PID" 2>/dev/null; then
        # Real outage observed during v0.6.5 development, not a hypothetical:
        # a colliding listen{} port (two NAS config files claiming the same
        # port — happened here from an orphaned file left behind by a raw
        # DB wipe that bypassed NasService::delete()'s cleanup) made
        # radiusd exit immediately on the restart above. Without this
        # check, the container was left with NO radiusd process at all
        # until the NEXT unrelated config change happened to trigger
        # another restart attempt — the Status-Server healthcheck kept
        # reporting stale "healthy" for up to one more interval, masking
        # the outage. Retrying here means a transient/config-collision
        # crash self-heals within one poll cycle once the bad file is
        # fixed/removed, instead of silently staying down indefinitely.
        echo ">> [entrypoint] radiusd (pid $RADIUSD_PID) is not running — restarting."
        start_radiusd
    fi

    # v0.6.5 CoA/Disconnect — boss-app drops one *.json request file per
    # call into coa-queue/, this loop is the only thing that ever actually
    # executes radclient (source IP must be this container's own static
    # FREERADIUS_INTERNAL_IP for RouterOS's /radius incoming to accept it —
    # see CoaService's own docblock), writing a *.result.json back.
    for request in "$COA_QUEUE_DIR"/*.json; do
        [ -e "$request" ] || continue
        case "$request" in
            *.result.json) continue ;;
        esac
        /coa-worker.sh "$request" &
    done
done
