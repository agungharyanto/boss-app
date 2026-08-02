#!/usr/bin/env bash
# BOSS App — Deploy script
# Selalu backup dulu sebelum deploy (RULE BOSS-012)

set -euo pipefail
cd "$(dirname "$0")/.."

echo "=== [1/6] Backup sebelum deploy ==="
./scripts/backup.sh

echo "=== [2/6] Pull kode terbaru ==="
git pull

echo "=== [3/6] Build asset frontend (Tailwind/Livewire) ==="
docker run --rm -v "$(pwd)/app":/app -w /app node:22-alpine sh -c "npm install && npm run build"

echo "=== [4/6] Build ulang image ==="
docker compose build

echo "=== [5/6] Restart container ==="
docker compose up -d

echo "=== [6/6] Jalankan migration ==="
docker compose exec -T boss-app php artisan migrate --force

echo ">> Deploy selesai. Cek: curl http://localhost/api/v1/health"
