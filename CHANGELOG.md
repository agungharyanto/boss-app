# Changelog

Format bebas mengikuti sprint di `docs/ROADMAP.md`. Setiap versi dicatat saat
tag dibuat (RULE BOSS-013).

## v0.3.1 — Personalization & Navigation

- **Theme customization**: primary & text color custom per user, disimpan di
  `user_preferences`, di-apply lewat CSS variables (Tailwind v4 `@theme`),
  live preview instan via Alpine sebelum disimpan.
- **Language switcher**: localization Laravel (`lang/id.json`, `lang/en.json`),
  middleware `SetLocale` (resolusi: session → preferensi user → default
  config), persist per user setelah login.
- **Sidebar cluster-dropdown**: grouping menu collapsible per cluster, state
  collapse tersimpan di `localStorage`, highlight active route.
- **Dashboard widget selector**: halaman `/dashboard` baru dengan 4 widget
  (Total Pelanggan, Pelanggan Terbaru, Status Registrasi, Agent Referral
  Teratas), visibility widget dikonfigurasi per user dan tersimpan di
  `user_preferences.dashboard_widgets`.
- Stack tetap Laravel + Blade + Livewire + Alpine.js + Tailwind — tidak ada
  penambahan framework frontend baru (bukan React/Next.js).

## v0.3.0 — Registration & Referral

- Tabel `agents` (tipe `sales`/`teknisi`/`freelance`/`admin`, opsional
  ter-link ke akun `users`) dan `commission_ledger` (status
  `pending`/`eligible`/`approved`/`paid`/`rejected`).
- Kolom tambahan di `customers`: `referred_by_agent_id`, `registration_status`
  (`registered`/`installed`/`active`), `registration_channel`, plus `nik`,
  `latitude`/`longitude`, `package`.
- `RegistrationService`: registrasi pelanggan transaksional — buat baris
  `commission_ledger` status `pending` otomatis kalau ada agent yang
  mereferensikan, tidak membuat apa pun kalau registrasi tanpa referral.
- Livewire `RegisterCustomer` (`/customers/register`) — dropdown agent
  referral auto-terisi & readonly untuk role sales/teknisi/freelance, manual
  untuk admin.
- Permission `register-customer` di-assign ke role existing (`super_admin`,
  `sales_internal`, `teknisi`, `sales_freelance`) — tidak ada role baru.
- `AgentSeeder`: 3 agent dummy untuk testing.

## v0.2.0 — Customer CRM

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
