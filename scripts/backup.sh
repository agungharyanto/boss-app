#!/usr/bin/env bash
# BOSS App — Backup database PostgreSQL
# Bisa dijalankan manual, atau dijadwalkan via crontab, misal:
# 0 2 * * * /opt/boss-app/scripts/backup.sh >> /opt/boss-app/backups/backup.log 2>&1

set -euo pipefail
cd "$(dirname "$0")/.."

source .env

mkdir -p backups
TIMESTAMP=$(date +%Y%m%d-%H%M%S)
FILE="backups/boss_db-${TIMESTAMP}.sql.gz"

docker compose exec -T boss-postgresql \
    pg_dump -U "${POSTGRES_USER}" "${POSTGRES_DB}" | gzip > "${FILE}"

echo ">> Backup tersimpan: ${FILE}"

# Retensi: simpan 14 backup terakhir saja
ls -1t backups/boss_db-*.sql.gz 2>/dev/null | tail -n +15 | xargs -r rm --
