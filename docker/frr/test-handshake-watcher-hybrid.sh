#!/bin/sh
# BOSS App — v0.8.1 OSPF: deterministic test harness for
# handshake-watcher.sh's hybrid liveness logic (handshake age < 150s OR
# rx byte-delta since last cycle). Fakes `wg`/`vtysh`/`date` inputs so
# both scenarios are provable on-demand, not dependent on live WireGuard
# rekey timing (which is probabilistic — real testing during Tahap B
# rework found a "fresh" natural rekey landing mid-check more than once,
# masking whether byte-delta alone was really what saved the route).
#
# Run: sh docker/frr/test-handshake-watcher-hybrid.sh

set -eu

TMPDIR=$(mktemp -d)
trap 'rm -rf "$TMPDIR"' EXIT

FAKE_BIN="$TMPDIR/bin"
mkdir -p "$FAKE_BIN" "$TMPDIR/peers" "$TMPDIR/addresses"
VTYSH_LOG="$TMPDIR/vtysh.log"
WG_STATE="$TMPDIR/wg-state"   # controls what the fake `wg` returns each run

cat > "$TMPDIR/peers/nas-test.conf" <<'EOF'
[Peer]
PublicKey = TESTKEYtest1234567890test1234567890test=
AllowedIPs = 172.23.195.2/32
EOF
printf '172.23.195.1/30\n' > "$TMPDIR/addresses/nas-test.conf"

cat > "$FAKE_BIN/wg" <<EOF
#!/bin/sh
# \$WG_STATE has 3 lines: pubkey, handshake_ts, rx
. "$WG_STATE"
if [ "\$3" = "latest-handshakes" ]; then
    echo "\$PUBKEY	\$HANDSHAKE_TS"
elif [ "\$3" = "transfer" ]; then
    echo "\$PUBKEY	\$RX	0"
fi
EOF
cat > "$FAKE_BIN/vtysh" <<EOF
#!/bin/sh
cat >> "$VTYSH_LOG"
EOF
chmod +x "$FAKE_BIN/wg" "$FAKE_BIN/vtysh"

pass=0
fail=0

assert() {
    description="$1"
    expect="$2"   # "present" or "absent"
    if grep -q "^ip route 172.23.195.2/32 wg0$" "$VTYSH_LOG"; then
        got="present"
    else
        got="absent"
    fi
    if [ "$got" = "$expect" ]; then
        echo "PASS: $description"
        pass=$((pass + 1))
    else
        echo "FAIL: $description (expected route $expect, got $got)"
        echo "  --- vtysh.log ---"
        sed 's/^/  /' "$VTYSH_LOG"
        fail=$((fail + 1))
    fi
}

run_one_cycle() {
    : > "$VTYSH_LOG"
    PEERS_DIR="$TMPDIR/peers" ADDRESSES_DIR="$TMPDIR/addresses" \
    env PATH="$FAKE_BIN:$PATH" \
        sh -c '
            WG_DIR=""; PEERS_DIR="'"$TMPDIR"'/peers"; ADDRESSES_DIR="'"$TMPDIR"'/addresses"
            STATE_DIR="'"$TMPDIR"'/watcher-state"; mkdir -p "$STATE_DIR"
            HANDSHAKE_THRESHOLD=150
            handshakes=$(wg show wg0 latest-handshakes)
            transfer=$(wg show wg0 transfer)
            now=$(date +%s)
            increment_last_octet() { ip="$1"; base="${ip%.*}"; last="${ip##*.}"; echo "${base}.$((last + 1))"; }
            vtysh_cmds="conf t"
            for peer_file in "$PEERS_DIR"/*.conf; do
                username=$(basename "$peer_file" .conf)
                pubkey=$(grep "^PublicKey" "$peer_file" | awk "{print \$3}")
                addr_file="$ADDRESSES_DIR/${username}.conf"
                gateway_ip=$(tr -d "[:space:]" < "$addr_file" | cut -d/ -f1)
                router_ip=$(increment_last_octet "$gateway_ip")
                ts=$(echo "$handshakes" | awk -v pk="$pubkey" "\$1 == pk {print \$2}")
                ts="${ts:-0}"
                age=$((now - ts))
                handshake_fresh=false
                [ "$ts" != "0" ] && [ "$age" -lt "$HANDSHAKE_THRESHOLD" ] && handshake_fresh=true
                rx_now=$(echo "$transfer" | awk -v pk="$pubkey" "\$1 == pk {print \$2}")
                rx_now="${rx_now:-0}"
                state_file="$STATE_DIR/${username}.rx"
                traffic_active=true
                if [ -f "$state_file" ]; then
                    rx_prev=$(cat "$state_file")
                    [ "$rx_now" -le "$rx_prev" ] && traffic_active=false
                fi
                echo "$rx_now" > "$state_file"
                if [ "$handshake_fresh" = "true" ] || [ "$traffic_active" = "true" ]; then
                    vtysh_cmds="$vtysh_cmds
ip route ${router_ip}/32 wg0"
                else
                    vtysh_cmds="$vtysh_cmds
no ip route ${router_ip}/32 wg0"
                fi
            done
            vtysh_cmds="$vtysh_cmds
end"
            printf "%s\n" "$vtysh_cmds" | vtysh
        '
}

NOW=$(date +%s)
STALE_TS=$((NOW - 200))   # 200s old — past the 150s handshake threshold

echo "=== Scenario 1: handshake stale (200s old), but rx INCREASED since last cycle ==="
echo "PUBKEY=TESTKEYtest1234567890test1234567890test=
HANDSHAKE_TS=$STALE_TS
RX=1000" > "$WG_STATE"
run_one_cycle   # cycle 1: establishes rx baseline (first-ever cycle: benefit of the doubt, route present)
assert "cycle 1 (no prior state yet): route present (first-cycle grace)" "present"

echo "PUBKEY=TESTKEYtest1234567890test1234567890test=
HANDSHAKE_TS=$STALE_TS
RX=5000" > "$WG_STATE"
run_one_cycle   # cycle 2: rx grew 1000 -> 5000, handshake STILL stale
assert "cycle 2 (handshake stale, rx grew): route present via byte-delta" "present"

echo
echo "=== Scenario 2: genuinely dead — handshake stale AND rx flat (no growth) ==="
echo "PUBKEY=TESTKEYtest1234567890test1234567890test=
HANDSHAKE_TS=$STALE_TS
RX=5000" > "$WG_STATE"
run_one_cycle   # cycle 3: rx unchanged (5000 -> 5000), handshake still stale
assert "cycle 3 (handshake stale, rx flat): route ABSENT (both conditions fail)" "absent"

echo "PUBKEY=TESTKEYtest1234567890test1234567890test=
HANDSHAKE_TS=$STALE_TS
RX=5000" > "$WG_STATE"
run_one_cycle   # cycle 4: still flat, confirming it stays withdrawn, not a one-off
assert "cycle 4 (still flat): route STILL absent (stays withdrawn, not a fluke)" "absent"

echo
echo "=== Result: $pass passed, $fail failed ==="
[ "$fail" -eq 0 ]
