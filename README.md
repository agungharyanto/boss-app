# BOSS App — Broadband Operations Support System

Platform operasional ISP modular. GenieACS, MikroTik, FreeRADIUS, LibreNMS, dan
WhatsApp (Baileys) menjadi service yang diintegrasikan; BOSS App adalah pusat
data pelanggan dan pusat perintah.

## Status

Sprint aktif: **v0.6.3-multi-protocol-vpn-script-generator**
Branch aktif: `v0.6.3-multi-protocol-vpn-script-generator`

Lihat `docs/RULES.md` untuk aturan pengembangan (BOSS-001 s/d BOSS-014) dan
`docs/ROADMAP.md` untuk urutan sprint yang sudah dikunci.

## Stack

| Layer      | Pilihan                          |
|------------|-----------------------------------|
| Backend    | Laravel 12 / PHP 8.4              |
| Frontend   | Blade + Livewire + Alpine + Tailwind |
| Database   | PostgreSQL                        |
| Cache/Queue| Redis                             |
| Web server | Nginx                             |
| Deployment | Docker Compose                    |
| WhatsApp   | Baileys (Node.js) — mulai v0.4.0  |
| RADIUS     | FreeRADIUS — mulai v0.6.1         |
| ACS        | GenieACS — mulai v0.7.0           |
| Monitoring | LibreNMS — mulai v0.8.0           |

## Quick start (server baru)

```bash
git clone <repo-url> boss-app
cd boss-app
cp .env.example .env
./scripts/01-setup-server.sh   # sekali saja, per server
./scripts/02-init-laravel.sh   # sekali saja, scaffold Laravel
docker run --rm -v "$(pwd)/app":/app -w /app node:22-alpine sh -c "npm install && npm run build"
docker compose up -d
docker compose exec boss-app php artisan migrate
docker compose exec boss-app php artisan db:seed --class=RolesAndPermissionsSeeder
```

Detail lengkap: `docs/DEPLOYMENT.md`
