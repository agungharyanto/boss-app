#!/usr/bin/env bash
# BOSS App — Deploy script
# Selalu backup dulu sebelum deploy (RULE BOSS-012)

set -euo pipefail
cd "$(dirname "$0")/.."

echo "=== [1/5] Backup sebelum deploy ==="
./scripts/backup.sh

echo "=== [2/5] Pull kode terbaru ==="
git pull

echo "=== [3/5] Build ulang image ==="
docker compose build

echo "=== [4/5] Restart container ==="
docker compose up -d

echo "=== [5/5] Jalankan migration ==="
docker compose exec -T boss-app php artisan migrate --force

echo ">> Deploy selesai. Cek: curl http://localhost/api/v1/health"
