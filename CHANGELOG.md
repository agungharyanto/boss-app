# Changelog

Format bebas mengikuti sprint di `docs/ROADMAP.md`. Setiap versi dicatat saat
tag dibuat (RULE BOSS-013).

## v0.3.3 — Regulatory Tax Engine

- Tabel baru `tax_components` — katalog pajak/pungutan dinamis (`code`
  stable identifier mis. `PPN`/`BHP_USO`/`PPH_BADAN`, `name` boleh di-rename
  admin, `type` `percentage`/`fixed`, `rate`), effective-dated
  (`effective_from`/`effective_to`) — perubahan tarif **tidak pernah**
  mengubah baris lama, selalu insert baris baru dengan `effective_from`
  baru lewat `TaxComponentService::updateRate()`, menutup baris lama di
  `effective_to`. Riwayat tarif jadi audit trail permanen.
- Tabel baru `reseller_tax_policies` — siapa menanggung pajak apa
  (`burden`: `customer_borne`/`reseller_borne`/`split`, `split_ratio` %
  ditanggung customer kalau `split`), per reseller **atau** direct-retail
  (`reseller_id` nullable). Partial unique index Postgres (bukan constraint
  biasa — `reseller_id` nullable butuh dua index terpisah, `WHERE reseller_id
  IS [NOT] NULL`) mencegah duplikasi policy aktif untuk kombinasi
  tenant+reseller+component+effective_from yang sama.
- Tabel baru `reseller_tax_ledger` — baris pajak per transaksi, snapshot
  `rate_applied`/`burden_applied` saat kalkulasi (tidak berubah walau
  `tax_components`/`reseller_tax_policies` di-update kemudian — audit
  trail). `reference_type`/`reference_id` polymorphic generic **tanpa** FK
  constraint (akan diisi `App\Models\Invoice` dst mulai v0.3.4 — lihat
  kontrak integrasi di `CLAUDE.md`). `source` (`seeded`/`system`) membedakan
  data uji coba dari data asli nanti.
- Tabel baru `komdigi_remittance_summary` — agregat bulanan per
  reseller×tax_component (termasuk baris direct-retail terpisah, sama pola
  partial-unique-index dengan `reseller_tax_policies`), dibuat via
  `RemittanceSummaryService::generateForPeriod()`.
- **Service layer (BOSS-006)**: `TaxComponentService` (create, updateRate
  effective-dated, toggleActive), `ResellerTaxPolicyService` (setPolicy
  dengan validasi split_ratio 0–100, getActivePolicies dengan fallback
  reseller-specific → direct-retail), `TaxCalculationService`
  (calculateForAmount → `App\DataTransferObjects\TaxBreakdown`,
  writeLedgerEntry — **kontrak stabil untuk v0.3.4**, lihat CLAUDE.md),
  `RemittanceSummaryService` (generateForPeriod, finalize).
- **Keputusan desain kunci**: policy↔component di-resolve lewat `code`
  (stable identifier), bukan `tax_component_id` mentah — supaya kesepakatan
  burden-sharing reseller tidak perlu di-set ulang setiap kali pemerintah
  mengubah tarif pajak (`updateRate()` selalu insert baris baru dengan id
  berbeda, code sama). Ditemukan sebagai bug saat testing (`calculateForAmount`
  mengembalikan tax=0 untuk periode setelah rate change), diperbaiki di
  `ResellerTaxPolicyService::getActivePolicies()`.
- **Bug tersembunyi lain yang ditemukan+diperbaiki**: `Illuminate\Database\Eloquent\Collection::merge()`
  di-override Laravel untuk merge berbasis primary key model, mengabaikan
  key kustom dari `keyBy()` — `getActivePolicies()` awalnya salah
  men-dedupe policy reseller-specific vs direct-retail karena ini. Fix:
  `->toBase()` sebelum `merge()` untuk kembali ke `Illuminate\Support\Collection`
  biasa yang merge berbasis key array, bukan primary key model.
- **REST API**: `GET/POST/PUT /api/v1/tax-components` +
  `POST .../update-rate` (admin-only), `GET/POST/PUT /api/v1/reseller-tax-policies`
  (admin + reseller owner scoped via `ResellerContext`, staff read-only),
  `GET /api/v1/tax-ledger`, `GET /api/v1/remittance-summary` +
  `POST .../generate` (admin-only, keduanya — ledger/summary detail belum
  diekspos ke reseller di sprint ini). Permission baru `tax_components.*`,
  `reseller_tax_policies.*`, `tax_ledger.view`, `remittance_summary.*` —
  hanya `super_admin`, mengikuti pola ketat `resellers.*` di v0.3.2 (role
  `billing`/`finance` TIDAK otomatis dapat akses).
- **Livewire UI**: `Tax\TaxComponentIndex` (CRUD + tombol "Update Rate"
  effective-dating), `Tax\ResellerTaxPolicyIndex` (admin pilih reseller
  manapun/direct-retail, reseller owner ter-scope otomatis ke reseller
  miliknya). Sidebar cluster baru "Billing & Finance".
- **Scope disesuaikan dari rencana awal** (fondasi tax engine saja, TANPA
  hook otomatis ke invoicing — invoicing core baru di v0.3.4): semua data
  transaksi diuji via `TaxEngineDummyDataSeeder` (local-only,
  `source='seeded'`), bukan trigger dari invoice sungguhan.
- Tests: 113/113 passing (105 existing + 8 file test baru mencakup
  kalkulasi percentage/fixed, split ratio, remittance aggregation,
  effective dating, dan otorisasi reseller owner/staff — lihat
  `tests/Feature/Api/TaxComponentApiTest.php`,
  `tests/Feature/Api/ResellerTaxPolicyApiTest.php`,
  `tests/Feature/Tax/`).

## v0.3.2 — Multi-Tenant Reseller

- Tabel baru `resellers` (child dari `tenant` — punya `tenant_id`, pakai
  trait `BelongsToTenant` yang sama seperti `Customer`/`Agent`), `slug`
  unique per tenant, `status` (`active`/`suspended`), soft delete.
- Tabel pivot `reseller_users` (`reseller_id`+`user_id`, `role`
  `owner`/`staff`, `status` `active`/`inactive`) — reseller bisa punya
  banyak staff. "Detach" staff (`DELETE /resellers/{id}/users/{user}`)
  sengaja soft: hanya mengubah `status` jadi `inactive`, tidak menghapus
  baris, supaya riwayat keanggotaan tetap ada.
- Tabel baru `reseller_package_pricing` — pricing package yang dibuat
  reseller sendiri untuk dijual ke customer-nya. **Tanpa `base_package_id`**:
  tidak ada tabel katalog `packages` ISP di codebase manapun sejauh ini,
  jadi setiap baris independen; `is_custom` murni flag kategorisasi manual.
- `customers.reseller_id` (nullable, `ON DELETE SET NULL`) — customer bisa
  milik reseller atau jadi direct customer ISP (`reseller_id` null).
- **Reseller context resolution**: middleware baru `ResolveResellerContext`
  (alias `reseller.context`) menentukan "user ini beroperasi sebagai
  reseller yang mana" dari keanggotaan `reseller_users` aktifnya, disimpan
  di `App\Support\ResellerContext` (container-bound singleton per-request,
  sengaja bukan session — lebih mudah di-test). `App\Models\Scopes\ResellerScope`
  (dipasang lewat trait opsional `BelongsToResellerScope` di `Customer` dan
  `ResellerPackagePricing`) otomatis memfilter query ke reseller itu **hanya
  kalau context ter-resolve** — user ISP admin/staff internal (tanpa
  keanggotaan reseller apa pun) tetap melihat semua data, sama seperti pola
  `TenantScope` untuk multi-tenancy. Middleware ini didaftarkan dengan
  prioritas eksplisit di depan `SubstituteBindings` (lihat `bootstrap/app.php`)
  supaya scope sudah aktif *sebelum* route-model-binding jalan — tanpa itu,
  akses ke package-pricing/customer milik reseller lain menghasilkan 403
  (baru ditolak di Policy) alih-alih 404 (ditolak dari resolusi binding-nya
  sendiri, isolasi yang lebih ketat, konsisten dengan `TenantIsolationTest`).
- Permission baru `resellers.view`/`resellers.manage`, hanya diberikan ke
  `super_admin` — reseller owner/staff diotorisasi lewat keanggotaan
  `reseller_users` mereka sendiri (`ResellerPolicy`, `CustomerPolicy`,
  `ResellerPackagePricingPolicy`), bukan lewat role/permission Spatie, karena
  mereka user eksternal (bisnis reseller), bukan staff internal ISP.
- **REST API (BOSS-006)**: `GET/POST /api/v1/resellers`,
  `GET/PUT/DELETE /api/v1/resellers/{reseller}`,
  `GET/POST /api/v1/resellers/{reseller}/users`,
  `DELETE /api/v1/resellers/{reseller}/users/{user}`,
  `GET/POST /api/v1/reseller-package-pricing`,
  `GET/PUT/DELETE /api/v1/reseller-package-pricing/{pricing}`. Business
  logic di `ResellerService`/`ResellerPackagePricingService`, dipakai bareng
  API controller dan komponen Livewire (`Resellers\ResellerIndex`,
  `Resellers\ResellerShow`, `Resellers\PackagePricingIndex`) — masuk sidebar
  cluster baru "Operasional". Lihat `docs/API.md`.
- **Scope disesuaikan dari rencana awal setelah inspeksi codebase** (lihat
  `docs/ROADMAP.md` untuk catatan dependency lengkap): tidak ada tabel
  `subscriptions` atau katalog `packages` ISP di codebase manapun sebelum
  sprint ini — keduanya baru direncanakan lahir di v0.3.4 (Invoicing Core).
  Bagian skema yang bergantung padanya (`subscriptions.reseller_id`,
  `subscriptions.reseller_package_pricing_id`, `reseller_package_pricing.base_package_id`)
  sengaja **tidak** dibuat di v0.3.2 — dicatat sebagai dependency wajib untuk
  v0.3.4 saat `subscriptions` benar-benar lahir, supaya v0.3.2 tetap sesuai
  RULE BOSS-003 (stay in scope) dan tidak diam-diam melahirkan desain
  `subscriptions` darurat yang bisa tabrakan dengan desain resmi nanti.
- **Bug infrastruktur ke-4 ditemukan & diperbaiki** (pola sama dengan 3 bug
  v0.1.0 yang sudah didokumentasikan di `CLAUDE.md`): root `.env` (dipakai
  Docker Compose sebagai `env_file`) punya `APP_ENV=production` padahal
  `app/.env` bilang `local`, dan process env container menang atas `.env` —
  server dev/sprint ini (`45.123.142.242`, satu-satunya server yang
  didokumentasikan di `docs/DEPLOYMENT.md`, direncanakan jadi production
  in-place saat go-live, bukan diganti server terpisah) diam-diam selalu
  `app()->environment() === 'production'` sejak awal. Diperbaiki dengan
  mengubah root `.env` (gitignored, bukan `.env.example`) jadi
  `APP_ENV=local` dan me-recreate container `boss-app`/`boss-worker`/
  `boss-scheduler`. Detail lengkap + reminder untuk flip balik ke
  `production` saat go-live sungguhan: lihat `CLAUDE.md`.
- Tests: 90/90 passing (84 existing + 6 file test baru mencakup CRUD
  reseller, staff management, package pricing CRUD, dan isolasi scope
  reseller — lihat `tests/Feature/Api/ResellerApiTest.php`,
  `tests/Feature/Api/ResellerPackagePricingApiTest.php`,
  `tests/Feature/Resellers/ResellerScopeIsolationTest.php`).

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
- **REST API (BOSS-006 gap closure)**: `POST /api/v1/registrations`,
  `GET /api/v1/referrals` (v0.3.0), dan `GET`/`PUT
  /api/v1/settings/{theme,locale,dashboard-widgets}` (v0.3.1) — sebelumnya
  kedua modul ini hanya punya UI Livewire tanpa REST API, melanggar aturan
  API-first. Business logic diekstrak/reuse lewat Service class
  (`ThemeSettingsService`, `LocaleService`, `DashboardWidgetService`,
  `RegistrationService`) yang dipakai bareng oleh controller API dan
  komponen Livewire. Lihat `docs/API.md`.

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
