# Changelog

Format bebas mengikuti sprint di `docs/ROADMAP.md`. Setiap versi dicatat saat
tag dibuat (RULE BOSS-013).

## v0.2.0 — Customer CRM (in progress)

- Data pelanggan (`customers`): profil, alamat, telepon utama, status
  lifecycle (`prospek`/`aktif`/`suspend`/`non_aktif`/`blacklist`) dengan
  validasi transisi.
- Kontak keluarga (`customer_contacts`): access level (`full`/`view_only`/
  `emergency`), permission flags, invariant "tepat satu authorized contact
  per pelanggan".
- Customer timeline (`customer_timeline_entries`): log otomatis (Model
  Observer) untuk semua perubahan status/profil/kontak, read-only via API.
- Permission granular Spatie per modul (`customers.view`, `customers.manage`,
  `customer_contacts.manage`, `customer_timeline.view`).
- API `Api/V1` lengkap (Migration → Enum → Model → Policy → Form Request →
  Action → Resource → Controller) + Livewire UI (`/customers`,
  `/customers/{id}`).
- **Fondasi multi-tenancy row-level**: tabel `tenants`, trait
  `BelongsToTenant` + `TenantScope` (global scope otomatis), diterapkan ke
  `Customer`/`CustomerContact`/`CustomerTimelineEntry`, kolom `tenant_id`
  wajib di `users`. `super_admin` tetap tenant-scoped (bukan role
  lintas-tenant).
- `DemoUsersSeeder`: satu tenant demo + satu user per role untuk testing
  manual.
- Perbaikan bug laten dari v0.1.0 (ditemukan saat membangun sprint ini):
  - Migration Sanctum (`personal_access_tokens`) tidak pernah di-publish —
    autentikasi token API tidak pernah benar-benar berfungsi.
  - Fortify tidak punya view login ter-bind — `/login` selalu 500.
  - `phpunit.xml` gagal mengisolasi test ke SQLite in-memory — seluruh test
    diam-diam berjalan ke database Postgres dev sungguhan.

## v0.1.0 — Foundation

- Scaffold Laravel 12 / PHP 8.4, Docker Compose (Nginx, Postgres, Redis,
  worker, scheduler).
- Fortify (auth), Sanctum (API token), Spatie Permission (role) — 8 role
  dasar: `super_admin`, `noc`, `customer_service`, `teknisi`, `billing`,
  `sales_internal`, `sales_freelance`, `finance`.
- `GET /api/v1/health` — health check app/database/redis.
- UFW + Fail2ban, backup script terjadwal.
