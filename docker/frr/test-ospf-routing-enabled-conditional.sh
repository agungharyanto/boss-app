#!/bin/sh
# BOSS App — v0.8.1 OSPF: regression test for the OSPF_ROUTING_ENABLED
# conditional added to the 3 modified entrypoint scripts.
#
# Not a PHPUnit test — these are plain POSIX shell entrypoint.sh files,
# no PHP involved at all, so there's nothing for `php artisan test` to
# exercise here. This is a standalone shell harness instead: it fakes
# `ip`/`getent` via a throwaway PATH directory that just logs every
# invocation instead of touching the real kernel routing table, runs each
# of the 3 target scripts under both OSPF_ROUTING_ENABLED=true and
# =false/unset, and asserts whether `ip route replace` for the
# OSPF-superseded route was actually invoked.
#
# Run: sh docker/frr/test-ospf-routing-enabled-conditional.sh

set -eu

TMPDIR=$(mktemp -d)
trap 'rm -rf "$TMPDIR"' EXIT

FAKE_BIN="$TMPDIR/bin"
mkdir -p "$FAKE_BIN"
LOG="$TMPDIR/calls.log"

cat > "$FAKE_BIN/ip" <<EOF
#!/bin/sh
echo "ip \$*" >> "$LOG"
exit 0
EOF
cat > "$FAKE_BIN/getent" <<EOF
#!/bin/sh
# Simulates DNS resolution for the 'openvpn'/'wireguard' container names
# freeradius/entrypoint.sh's refresh_coa_routes() looks up.
if [ "\$2" = "openvpn" ]; then echo "172.28.0.16 openvpn"; fi
if [ "\$2" = "wireguard" ]; then echo "172.28.0.11 wireguard"; fi
exit 0
EOF
chmod +x "$FAKE_BIN/ip" "$FAKE_BIN/getent"

pass=0
fail=0

assert_route_call() {
    description="$1"
    expect_present="$2"   # "yes" or "no"
    pattern="$3"

    if grep -q "$pattern" "$LOG" 2>/dev/null; then
        found="yes"
    else
        found="no"
    fi

    if [ "$found" = "$expect_present" ]; then
        echo "PASS: $description"
        pass=$((pass + 1))
    else
        echo "FAIL: $description (expected route call present=$expect_present, got=$found)"
        echo "  --- full call log ---"
        cat "$LOG" 2>/dev/null | sed 's/^/  /'
        fail=$((fail + 1))
    fi
}

run_case() {
    script="$1"
    extra_env="$2"
    : > "$LOG"
    # shellcheck disable=SC2086
    env PATH="$FAKE_BIN:$PATH" $extra_env sh "$script" </dev/null >/dev/null 2>&1 || true
}

echo "=== docker/freeradius/entrypoint.sh (refresh_coa_routes, WireGuard half only) ==="
# freeradius/entrypoint.sh does a lot more than refresh_coa_routes (sed
# patches to raddb config files that don't exist in this sandbox) — it
# would fail early on those before ever reaching refresh_coa_routes.
# Extract just the function + its call, matching the real file's current
# content, rather than sourcing the whole script.
FR_SNIPPET="$TMPDIR/freeradius-snippet.sh"
awk '/^refresh_coa_routes\(\) \{/,/^refresh_coa_routes$/' /opt/boss-app/docker/freeradius/entrypoint.sh > "$FR_SNIPPET"
echo "#!/bin/sh" | cat - "$FR_SNIPPET" > "$TMPDIR/fr.sh"

run_case "$TMPDIR/fr.sh" "OSPF_ROUTING_ENABLED=false WG_SUBNET_CIDR=172.23.195.0/24 VPN_SUBNET_CIDR=172.23.194.0/24"
assert_route_call "OSPF disabled: WireGuard subnet route IS added via node1" "yes" "172.23.195.0/24 via 172.28.0.11"
assert_route_call "OSPF disabled: OpenVPN subnet route is ALSO always added (unaffected by flag)" "yes" "172.23.194.0/24 via 172.28.0.16"

run_case "$TMPDIR/fr.sh" "OSPF_ROUTING_ENABLED=true WG_SUBNET_CIDR=172.23.195.0/24 VPN_SUBNET_CIDR=172.23.194.0/24"
assert_route_call "OSPF enabled: WireGuard subnet route is SKIPPED" "no" "172.23.195.0/24 via"
assert_route_call "OSPF enabled: OpenVPN subnet route is STILL added (out of OSPF scope)" "yes" "172.23.194.0/24 via 172.28.0.16"

echo
echo "=== docker/genieacs/entrypoint.sh ==="
GA_SNIPPET="$TMPDIR/genieacs-snippet.sh"
{
    echo "#!/bin/sh"
    sed -n '/^if \[ "\${OSPF_ROUTING_ENABLED/,/^fi$/p' /opt/boss-app/docker/genieacs/entrypoint.sh
} > "$GA_SNIPPET"

run_case "$GA_SNIPPET" "OSPF_ROUTING_ENABLED=false TR069_MANAGEMENT_SUBNET=10.1.0.0/20 TR069_MANAGEMENT_GATEWAY=172.28.0.5"
assert_route_call "OSPF disabled: TR069_MANAGEMENT route IS added" "yes" "10.1.0.0/20 via 172.28.0.5"

run_case "$GA_SNIPPET" "OSPF_ROUTING_ENABLED=true TR069_MANAGEMENT_SUBNET=10.1.0.0/20 TR069_MANAGEMENT_GATEWAY=172.28.0.5"
assert_route_call "OSPF enabled: TR069_MANAGEMENT route is SKIPPED" "no" "10.1.0.0/20 via"

echo
echo "=== docker/librenms/route-init.sh ==="
LN_SNIPPET="$TMPDIR/librenms-snippet.sh"
{
    echo "#!/bin/sh"
    sed -n '/^if \[ "\${OSPF_ROUTING_ENABLED/,/^fi$/p' /opt/boss-app/docker/librenms/route-init.sh
} > "$LN_SNIPPET"

run_case "$LN_SNIPPET" "OSPF_ROUTING_ENABLED=false OLT_MANAGEMENT_SUBNET=10.168.100.0/24 OLT_MANAGEMENT_GATEWAY=172.28.0.5"
assert_route_call "OSPF disabled: OLT_MANAGEMENT route IS added" "yes" "10.168.100.0/24 via 172.28.0.5"

run_case "$LN_SNIPPET" "OSPF_ROUTING_ENABLED=true OLT_MANAGEMENT_SUBNET=10.168.100.0/24 OLT_MANAGEMENT_GATEWAY=172.28.0.5"
assert_route_call "OSPF enabled: OLT_MANAGEMENT route is SKIPPED" "no" "10.168.100.0/24 via"

echo
echo "=== Result: $pass passed, $fail failed ==="
[ "$fail" -eq 0 ]
