#!/bin/sh
set -eu

PATH=/opt/sbin:/opt/bin:$PATH
export PATH

# Invoked by entrypoint.sh's watch loop, one call per queued CoA/Disconnect
# request file (coa-queue/*.json, written by App\Services\Network\CoaService
# — see its own docblock for why this must run from inside THIS container
# specifically: the packet's source IP must be FREERADIUS_INTERNAL_IP,
# which is this container's own real address, for a NAS's RouterOS
# `/radius incoming` to accept it at all).
#
# Request shape: {"target_ip": "...", "port": 3799, "secret": "...",
#                  "username": "...", "type": "disconnect"|"coa"}
# Result shape:  {"ok": true|false, "raw": "<radclient output>"}

request_file="$1"
result_file="${request_file%.json}.result.json"

target_ip=$(jq -r '.target_ip' "$request_file")
port=$(jq -r '.port' "$request_file")
secret=$(jq -r '.secret' "$request_file")
username=$(jq -r '.username' "$request_file")
type=$(jq -r '.type' "$request_file")

packet_type="disconnect"
[ "$type" = "coa" ] && packet_type="coa-request"

# radclient reads secret from a file (-S), not argv — same reasoning as
# every other place in this codebase that keeps a RADIUS/VPN secret out of
# a process list (`ps aux` on a shared host is a real, if narrow, exposure
# otherwise).
secret_file=$(mktemp)
printf '%s' "$secret" > "$secret_file"

set +e
output=$(printf 'User-Name=%s\n' "$username" | radclient -x "${target_ip}:${port}" "$packet_type" -S "$secret_file" 2>&1)
set -e

rm -f "$secret_file"

if echo "$output" | grep -qiE '(Disconnect|CoA)-ACK'; then
    ok=true
else
    ok=false
fi

jq -n --argjson ok "$ok" --arg raw "$output" '{ok: $ok, raw: $raw}' > "$result_file"

rm -f "$request_file"
