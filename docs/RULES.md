# BOSS App — Aturan Pengembangan

## RULE BOSS-001 — GitHub sebagai sumber utama
Semua perubahan wajib di GitHub: source code, Docker Compose, Nginx config,
struktur PostgreSQL, konfigurasi Redis, FreeRADIUS, GenieACS, LibreNMS,
Baileys, firewall, backup script, monitoring, migration, dokumentasi instalasi,
`.env.example`, script deployment/update. Tidak ada config produksi yang cuma
hidup di server. Pengecualian hanya rahasia (password, token, private key,
DB credential, RADIUS secret, WhatsApp session, encryption key) — masuk `.env`,
formatnya di `.env.example`.

## RULE BOSS-002 — Satu versi, satu chat
Tidak pindah ke versi berikutnya sebelum: fitur selesai, testing berhasil,
git status bersih, commit berhasil, push berhasil, tag berhasil.

## RULE BOSS-003 — Tidak keluar dari scope
Setiap sprint scope-nya dikunci. Kebutuhan baru masuk backlog versi
berikutnya, bukan disisipkan ke sprint aktif.

## RULE BOSS-004 — Eksekusi berbasis sprint
Setiap sprint berisi: Tujuan, File yang dibuat/diubah, Perintah terminal,
Full script, Cara pengujian, Expected result, Commit, Push, Tag. Teori hanya
diberikan saat dibutuhkan untuk keputusan atau troubleshooting.

## RULE BOSS-005 — Security-first
Layer: Internet → Cloudflare (opsional) → UFW → Fail2ban → Nginx → TLS →
Laravel Auth (Fortify) → Role & Permission (Spatie) → REST API Token
(Sanctum) → Rate Limiting → Audit Log.

## RULE BOSS-006 — API-first
Semua modul utama punya service + REST API (`/api/v1/...`) walau UI awal
pakai Blade/Livewire. Business logic di Service/Action, bukan di Controller.
Validasi pakai Form Request. Response format konsisten (lihat contoh di PDF
modul pengembangan).

## RULE BOSS-007 — Container-first
Semua service pendukung jalan di container, ditambahkan bertahap per versi
(bukan sekaligus) agar troubleshooting tetap mudah.

## RULE BOSS-008 — Data graph dan monitoring
LibreNMS tetap dipakai sebagai monitoring engine (bukan dibangun ulang).
BOSS App ambil data via LibreNMS API/adapter, bukan baca file RRD langsung.

## RULE BOSS-009 — Database dipisahkan secara logis
`boss_db` (PostgreSQL, BOSS App), `radius_db` (FreeRADIUS), `genieacs_db`
(MongoDB), `librenms_db` (MariaDB). Integrasi lewat API/service, bukan join
lintas database.

## RULE BOSS-010 — Public server hardening
Port publik awal: 22 (SSH, dibatasi), 80, 443. Tidak boleh terbuka ke publik:
5432 (PostgreSQL), 6379 (Redis), 27017 (MongoDB), 3306 (MariaDB), 1812/1813
(RADIUS — kecuali dari IP NAS), 7547 (GenieACS CWMP — kebijakan khusus).

## RULE BOSS-011 — Konfigurasi harus reproducible
Server baru harus bisa dibangun ulang hanya dengan `git clone` →
`cp .env.example .env` → `docker compose up -d` + script provisioning di repo.

## RULE BOSS-012 — Backup dan rollback
Setiap versi production wajib: backup database, backup volume penting,
migration aman, prosedur rollback, tag Git, catatan perubahan.

## RULE BOSS-013 — Naming dan versioning
Repo: `boss-app`. Branch: `main`, `develop`, `v0.1.0-foundation`,
`v0.2.0-customer-crm`, dst. Commit: `v0.1.0 initialize BOSS App foundation`.
Tag: `v0.1.0`, `v0.2.0`, `v1.0.0`.

## RULE BOSS-014 — WhatsApp Gateway
Seluruh integrasi WhatsApp pakai **Baileys** sebagai gateway (bukan WAHA,
Wablas, MPWA, atau WhatsApp Cloud API). Terpusat di satu service Node.js,
diakses BOSS App lewat REST API internal. Diimplementasikan mulai v0.4.0.

## Definition of Done (tiap sprint)
- [ ] Seluruh file masuk GitHub
- [ ] Tidak ada secret dalam repository
- [ ] Docker container berjalan sehat
- [ ] API test berhasil
- [ ] Migration berhasil
- [ ] Permission diuji
- [ ] Firewall diuji
- [ ] Backup diuji
- [ ] Dokumentasi diperbarui
- [ ] Git status bersih
- [ ] Commit dan push berhasil
- [ ] Tag versi berhasil
