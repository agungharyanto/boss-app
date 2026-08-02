#!/usr/bin/env bash
# BOSS App — Rollback ke tag versi tertentu
# Usage: ./scripts/rollback.sh v0.1.0

set -euo pipefail
cd "$(dirname "$0")/.."

TAG="${1:-}"
if [ -z "$TAG" ]; then
    echo "Usage: $0 <tag>"
    echo "Tag tersedia:"
    git tag
    exit 1
fi

echo "=== Rollback ke ${TAG} ==="
git fetch --tags
git checkout "tags/${TAG}"

docker compose build
docker compose up -d

echo ">> Rollback ke ${TAG} selesai. NOTE: migration TIDAK di-rollback otomatis."
echo "   Jika perlu restore database, gunakan file backup di backups/ secara manual."
