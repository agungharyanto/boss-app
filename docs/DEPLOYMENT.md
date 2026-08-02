# BOSS App — Panduan Deployment v0.1.0-foundation

Panduan ini asumsi: 1 VM Ubuntu 24.04 di Proxmox, IP Public, belum ada domain.

---

## Bagian A — Setup GitHub (dilakukan di laptop kamu, bukan di server)

### A1. Buat repository di GitHub
1. Buka https://github.com/new
2. Repository name: `boss-app`
3. Visibility: **Private** (data pelanggan/ISP sensitif — jangan public)
4. **Jangan** centang "Add a README" (kita sudah punya)
5. Create repository

### A2. Push isi project ini ke GitHub

Dari folder `boss-app` (folder yang sudah saya siapkan ini):

```bash
cd boss-app
git init
git add .
git commit -m "v0.1.0 initialize BOSS App foundation"
git branch -M main
git remote add origin git@github.com:USERNAME/boss-app.git
git push -u origin main
```

Kalau belum pernah setup SSH key ke GitHub:

```bash
ssh-keygen -t ed25519 -C "email-kamu@example.com"
cat ~/.ssh/id_ed25519.pub
```

Copy output-nya, buka https://github.com/settings/keys → **New SSH key** →
paste → save. Baru jalankan `git push` di atas.

Alternatif kalau tidak mau pakai SSH key: pakai HTTPS + Personal Access
Token (Settings → Developer settings → Personal access tokens → generate,
scope `repo`), lalu:

```bash
git remote add origin https://github.com/USERNAME/boss-app.git
git push -u origin main
# saat diminta password, paste Personal Access Token
```

### A3. Buat branch kerja sesuai RULE BOSS-013

```bash
git checkout -b develop
git push -u origin develop
git checkout -b v0.1.0-foundation
git push -u origin v0.1.0-foundation
```

Kerja sprint ini dilakukan di branch `v0.1.0-foundation`. Setelah Definition
of Done terpenuhi (lihat `docs/RULES.md`), merge ke `develop`, lalu ke `main`,
lalu buat tag `v0.1.0`.

---

## Bagian B — Setup Server (dilakukan di VM Ubuntu 24.04)

### B1. Clone repo ke server

```bash
sudo mkdir -p /opt/boss-app
sudo chown $USER:$USER /opt/boss-app
git clone -b v0.1.0-foundation git@github.com:USERNAME/boss-app.git /opt/boss-app
cd /opt/boss-app
```

### B2. Setup server (Docker, UFW, Fail2ban)

```bash
./scripts/01-setup-server.sh
```

Script ini meng-allow port SSH di UFW lewat env var `SSH_PORT` (default `22`
kalau tidak di-set). **Server produksi ini memakai SSH di port non-default
49194, bukan 22** — jadi jalankan dengan:

```bash
SSH_PORT=49194 ./scripts/01-setup-server.sh
```

Jangan hardcode `22/tcp` di UFW kalau daemon SSH sudah dipindah ke port lain
(cek `/etc/ssh/sshd_config` di server untuk port yang sebenarnya dipakai) —
kalau port UFW tidak cocok dengan port SSH aktif, sesi SSH bisa terkunci
begitu `ufw enable` jalan dengan `default deny incoming`.

Kalau ini instalasi Docker pertama kali, **logout dan login ulang** ke SSH
session (supaya group `docker` berlaku), baru lanjut.

### B3. Siapkan .env

```bash
cp .env.example .env
nano .env
```

Isi minimal yang **wajib** diubah dari default:
- `DB_PASSWORD` dan `POSTGRES_PASSWORD` (harus sama persis)
- `REDIS_PASSWORD`
- `APP_URL` → ganti `YOUR_SERVER_IP` dengan IP Public VM kamu

### B4. Scaffold Laravel

```bash
./scripts/02-init-laravel.sh
```

Generate `APP_KEY`:

```bash
docker compose run --rm boss-app php artisan key:generate --show
```

Copy hasilnya (`base64:...`) ke `.env` pada baris `APP_KEY=`.

### B4.5. Build asset frontend (Tailwind/Livewire)

Container `boss-app` sengaja tidak berisi Node.js (RULE BOSS-007 — image tetap
ramping, cukup PHP). Compile CSS/JS pakai container Node sementara, sama seperti
pola composer di `02-init-laravel.sh`:

```bash
docker run --rm -v "$(pwd)/app":/app -w /app node:22-alpine sh -c "npm install && npm run build"
```

Tanpa langkah ini, halaman Livewire (mis. `/customers`) tetap berfungsi tapi
tampil tanpa styling Tailwind. `scripts/deploy.sh` sudah menjalankan ini
otomatis untuk deploy berikutnya.

### B5. Nyalakan semua service

```bash
docker compose up -d --build
docker compose ps
```

Semua container harus `Up` / `healthy`.

### B6. Migration & seed role dasar

```bash
docker compose exec boss-app php artisan migrate
docker compose exec boss-app php artisan db:seed --class=RolesAndPermissionsSeeder
```

### B7. Test

```bash
curl http://localhost/api/v1/health
```

Expected:
```json
{"success":true,"message":"BOSS App healthy","data":{"app":true,"database":true,"redis":true},"meta":{...}}
```

Dari luar (browser laptop kamu): `http://IP_PUBLIC_VM/api/v1/health`

### B8. Commit hasil scaffold Laravel ke GitHub

Laravel yang baru di-scaffold (`app/`) juga harus masuk repo (RULE BOSS-001):

```bash
git add .
git commit -m "v0.1.0 scaffold laravel, fortify, spatie permission, health api"
git push
```

### B9. Setup backup terjadwal

```bash
crontab -e
```

Tambahkan baris:
```
0 2 * * * /opt/boss-app/scripts/backup.sh >> /opt/boss-app/backups/backup.log 2>&1
```

---

## Bagian C — Tag versi (setelah semua checklist RULES.md ✅)

```bash
git checkout develop
git merge v0.1.0-foundation
git checkout main
git merge develop
git tag -a v0.1.0 -m "BOSS App v0.1.0 — Foundation"
git push origin main develop --tags
```

---

## HTTPS (menyusul, belum di v0.1.0)

Karena server masih pakai IP Public tanpa domain, HTTPS ditunda. Begitu ada
domain yang mengarah ke IP ini:
1. Tambahkan service `certbot` di `docker-compose.yml`
2. Uncomment blok `acme-challenge` di `docker/nginx/conf.d/app.conf`
3. Jalankan certbot untuk terbitkan sertifikat Let's Encrypt
4. Buka port 443 (sudah di-allow UFW dari awal)

Detailnya akan kita bahas sebagai bagian dari sprint terkait, bukan sekarang
(supaya tidak keluar dari scope v0.1.0-foundation — RULE BOSS-003).
