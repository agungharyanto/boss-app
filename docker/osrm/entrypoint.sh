#!/bin/bash
# v0.16.0 Langkah 11 — one-time preprocessing then serve. The
# osrm-extract/partition/customize pipeline is expensive (several
# minutes) and its output is deterministic for a given .osm.pbf, so it
# runs ONCE and a marker file makes every later container start skip
# straight to osrm-routed — same "process once, persist to a volume,
# re-attach on boot" idiom as docker/openvpn's PKI bootstrap.
set -euo pipefail

DATA_DIR=/data
PBF="$DATA_DIR/region.osm.pbf"
OSRM="$DATA_DIR/region.osrm"
PROFILE=/opt/car.lua
MARKER="$DATA_DIR/.osrm-ready"
URL="${OSRM_PBF_URL:-https://download.geofabrik.de/asia/indonesia/java-latest.osm.pbf}"

if [ ! -f "$MARKER" ]; then
    echo "[osrm] no processed dataset found — running first-time preprocessing"

    if [ ! -f "$PBF" ]; then
        echo "[osrm] downloading extract: $URL"
        curl -fSL --retry 3 --retry-delay 5 -o "$PBF.partial" "$URL"
        mv "$PBF.partial" "$PBF"
        echo "[osrm] downloaded $(du -h "$PBF" | cut -f1)"
    fi

    echo "[osrm] osrm-extract (profile: car)"
    osrm-extract -p "$PROFILE" "$PBF"
    echo "[osrm] osrm-partition"
    osrm-partition "$OSRM"
    echo "[osrm] osrm-customize"
    osrm-customize "$OSRM"

    # the .osm.pbf is only needed for (re)processing — keep it so a
    # future re-customize is possible without re-downloading, it's small
    # next to the .osrm.* fileset.
    touch "$MARKER"
    echo "[osrm] preprocessing complete — dataset ready at $OSRM"
fi

echo "[osrm] starting osrm-routed (MLD, max-alternatives 5) on :5000"
exec osrm-routed --algorithm mld --max-alternatives 5 --port 5000 "$OSRM"
