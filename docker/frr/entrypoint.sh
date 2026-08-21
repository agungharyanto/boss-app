#!/bin/sh
set -eu

# BOSS App — v0.8.1 OSPF sidecar entrypoint. One shared image for all 8
# targets (3 WireGuard nodes = FRR_ROLE=node, 5 consumers = FRR_ROLE=
# consumer) — see CLAUDE.md's OSPF section for the full design.
#
# Deliberately does NOT go through frrinit.sh/watchfrr (FRR's own
# distro-init-style launcher) — tried it first, but frrinit.sh's
# daemon_start/daemon_prep machinery assumes a real init system
# (PID-file conventions, install(1)-based directory bootstrap tuned for
# openrc/systemd) that adds real complexity/failure modes in a bare
# container without a clear win over the simpler approach below. zebra
# (background) + ospfd (foreground, PID1) is the same shape already
# verified stable for 13 minutes of real `docker stats` measurement
# during the resource-test phase. Crash recovery is delegated to
# Docker's own `restart: unless-stopped` on the sidecar service itself
# (both daemons always need to restart together anyway — ospfd's zapi
# connection to zebra doesn't survive zebra dying on its own), not to
# watchfrr's finer-grained per-daemon restart logic.

FRR_ROLE="${FRR_ROLE:?FRR_ROLE must be set to 'node' or 'consumer'}"
OSPF_HELLO_INTERVAL="${OSPF_HELLO_INTERVAL:-1}"
OSPF_DEAD_INTERVAL="${OSPF_DEAD_INTERVAL:-4}"
: "${OSPF_AUTH_KEY:?OSPF_AUTH_KEY must be set}"

mkdir -p /etc/frr /var/run/frr /var/log/frr

case "$FRR_ROLE" in
    node)     template=/frr.conf.node.template ;;
    consumer) template=/frr.conf.consumer.template ;;
    *) echo "FRR_ROLE must be 'node' or 'consumer', got: $FRR_ROLE" >&2; exit 1 ;;
esac

# Router ID: this container's own IP on the shared boss-network netns
# (network_mode: service:<target> means this IS the target's real,
# already-pinned static IP — deterministic, no risk of FRR auto-electing
# a different interface's address).
router_id=$(ip -4 -o addr show eth0 | awk '{print $4}' | cut -d/ -f1)

sed \
    -e "s/__HELLO__/${OSPF_HELLO_INTERVAL}/g" \
    -e "s/__DEAD__/${OSPF_DEAD_INTERVAL}/g" \
    -e "s/__AUTH_KEY__/${OSPF_AUTH_KEY}/g" \
    -e "s/__ROUTER_ID__/${router_id}/g" \
    "$template" > /etc/frr/ospfd.conf

# Real bug found deploying this for the first time (Tahap A, frr-wireguard):
# `router ospf`/`network .../ ip ospf hello-interval` etc. are OSPFD-only
# commands — zebra doesn't understand any of them and, pointed at the SAME
# file, logged a "No such command" warning for every single line (harmless
# — zebra just skips what it doesn't recognize and starts fine regardless
# — but noisy and architecturally wrong). Fixed by giving zebra its own
# minimal file with only what it actually needs (hostname/log), rather
# than a genuinely single shared "integrated" config — proper integrated
# config would need vtysh's own boot-time distribution mechanism, not a
# plain `-f` pointed at the same file by two independent daemons.
cat > /etc/frr/zebra.conf <<EOF
hostname frr-${FRR_ROLE}
log stdout
EOF

chown -R frr:frr /etc/frr /var/run/frr /var/log/frr

echo ">> [frr-entrypoint] role=${FRR_ROLE} router-id=${router_id} hello=${OSPF_HELLO_INTERVAL}s dead=${OSPF_DEAD_INTERVAL}s"
echo ">> [frr-entrypoint] starting zebra..."
/usr/lib/frr/zebra -f /etc/frr/zebra.conf -u frr -g frr &

# zebra needs a moment to create its vty/zapi socket before ospfd (and,
# for node role, mgmtd/staticd/handshake-watcher's vtysh calls) can
# connect to it.
sleep 2

if [ "$FRR_ROLE" = "node" ]; then
    # Real bug found live-testing handshake-watcher.sh's actual vtysh
    # calls (not caught by config-file loading, which worked fine without
    # these): FRR 10.x routes `ip route ...` static-route commands issued
    # over vtysh through `mgmtd` (its newer central config-transaction
    # daemon) to `staticd` (which now owns static routes, not zebra
    # directly) — neither was running, so every handshake-watcher static
    # route add/remove silently failed with "mgmtd is not running" (only
    # visible when running vtysh interactively; the watcher's own
    # `>/dev/null 2>&1` swallowed it). Config-FILE loading via `-f` (what
    # ospfd.conf/zebra.conf use) does NOT need these — only live vtysh
    # reconfiguration of static routes does, which only ever happens on a
    # node-role sidecar (handshake-watcher.sh), never on a consumer.
    echo ">> [frr-entrypoint] starting mgmtd..."
    /usr/lib/frr/mgmtd -u frr -g frr &
    echo ">> [frr-entrypoint] starting staticd..."
    /usr/lib/frr/staticd -u frr -g frr &
    sleep 2

    echo ">> [frr-entrypoint] starting handshake-watcher..."
    /handshake-watcher.sh &
fi

echo ">> [frr-entrypoint] starting ospfd (foreground, PID1)..."
exec /usr/lib/frr/ospfd -f /etc/frr/ospfd.conf -u frr -g frr
