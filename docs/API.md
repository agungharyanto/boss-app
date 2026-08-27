# BOSS App — API Reference

Semua endpoint di bawah `prefix('v1')`, base URL `http://<host>/api/v1`.

## Konvensi umum

**Autentikasi**: Sanctum token (`Authorization: Bearer <token>`), kecuali `GET /health`.

**Response envelope** (konsisten di semua endpoint, lihat `HealthController`):

```json
{
  "success": true,
  "message": "Pesan singkat",
  "data": { "...": "..." },
  "meta": { "...": "..." }
}
```

Error validasi (422) dan error otorisasi/404 memakai format bawaan Laravel
(`{"message": "...", "errors": {...}}`), bukan envelope di atas.

**Multi-tenancy**: setiap request otomatis di-scope ke tenant milik user yang
login (`auth()->user()->tenant_id`) lewat global scope. Data milik tenant lain
tidak akan pernah muncul di response, dan mengakses record ID milik tenant
lain langsung menghasilkan `404` (bukan `403`) karena route model binding
tidak menemukannya sama sekali.

---

## Health

### `GET /health`

Publik, tanpa autentikasi. Mengecek koneksi database dan Redis.

```json
{
  "success": true,
  "message": "BOSS App healthy",
  "data": { "app": true, "database": true, "redis": true },
  "meta": { "version": "v0.1.0-foundation", "timestamp": "..." }
}
```

Status `503` kalau salah satu check gagal (`success: false`).

## Auth

### `GET /me`

Butuh Sanctum token. Mengembalikan data user yang sedang login.

---

## Customers

Permission: `customers.view` (semua role) untuk baca, `customers.manage`
(`customer_service`, `superadmin`) untuk tulis.

### `GET /customers`

Query params: `search` (nama/telepon), `status` (salah satu dari
`prospek`/`aktif`/`suspend`/`non_aktif`/`blacklist`), `per_page`.

### `POST /customers`

Body: `name`, `address`, `phone_number` (semua wajib). Status awal selalu
`prospek` — tidak bisa di-set lewat endpoint ini.

### `GET /customers/{customer}`

### `PUT/PATCH /customers/{customer}`

Body: `name`, `address`, `phone_number` (semua opsional, partial update).
Tidak bisa mengubah `status` lewat sini — pakai endpoint status di bawah.

### `PATCH /customers/{customer}/status`

Body: `status` (wajib, salah satu nilai `CustomerStatus`).

Aturan transisi (`App\Enums\CustomerStatus::canTransitionTo`):
- `prospek` → `aktif` (satu arah)
- `aktif` ↔ `suspend` ↔ `non_aktif` (bebas bolak-balik antar tiga ini)
- status manapun → `blacklist` (terminal — tidak ada jalan keluar)

Transisi yang tidak valid → `422` dengan pesan di bawah field `status`.

---

## Customer Contacts (kontak keluarga)

Permission: `customers.view` untuk baca, `customer_contacts.manage`
(`customer_service`, `superadmin`) untuk tulis. Nested di bawah customer —
mengakses contact lewat `customer_id` yang salah (termasuk milik tenant lain)
menghasilkan `404`.

### `GET /customers/{customer}/contacts`

### `POST /customers/{customer}/contacts`

Body: `name`, `phone_number` (wajib), `relationship` (nullable),
`access_level` (wajib — `full`/`view_only`/`emergency`), `can_view_billing`,
`can_request_service_change`, `can_receive_notifications`,
`is_authorized_contact` (semua boolean, opsional).

Menandai `is_authorized_contact: true` otomatis meng-unmark kontak lain yang
sebelumnya jadi authorized contact pelanggan ini — hanya boleh tepat satu.

### `GET /customers/{customer}/contacts/{contact}`

### `PUT/PATCH /customers/{customer}/contacts/{contact}`

Field sama seperti create, semua opsional (partial update).

### `DELETE /customers/{customer}/contacts/{contact}`

---

## Customer Timeline

Read-only. Permission: `customer_timeline.view` (semua role).

### `GET /customers/{customer}/timeline`

Log otomatis (via Model Observer) untuk setiap perubahan status, profil, dan
kontak. `event_type` yang mungkin muncul: `customer_created`,
`status_changed`, `profile_updated`, `contact_created`, `contact_updated`,
`contact_deleted`. Setiap entry immutable (tidak ada endpoint update/delete).

---

## Registration & Referral

Permission: `register-customer` (`superadmin`, `sales_internal`, `teknisi`,
`sales_freelance`). Business logic ada di `App\Services\RegistrationService`,
dipakai bareng oleh endpoint ini dan Livewire `RegisterCustomer`.

### `POST /registrations`

Body: `name`, `address`, `phone_number` (wajib), `nik`, `latitude`,
`longitude`, `package` (opsional), `referred_by_referrer_id` (opsional, harus
`id` referrer milik tenant yang sama).

> **Breaking change v0.9.1**: field ini sebelumnya bernama `referred_by_agent_id`
> (model `Agent` di-rename jadi `Referrer` — lihat CLAUDE.md bagian v0.9.1 untuk
> alasannya). Project masih pre-production/belum ada consumer eksternal, jadi
> rename field dilakukan langsung tanpa periode transisi/alias.

Aturan atribusi referrer: kalau user yang login sudah terhubung ke sebuah
`Referrer` (`referrers.user_id`), registrasi **selalu** diatribusikan ke
referrer itu — `referred_by_referrer_id` di body diabaikan. Kalau user tidak
terhubung ke referrer manapun (mis. `superadmin` mendaftarkan langsung),
`referred_by_referrer_id` dipakai kalau dikirim, atau `registration_channel`
jadi `admin` tanpa referral kalau tidak.

Setiap registrasi dengan referrer otomatis membuat satu baris `commission_ledger`
berstatus `pending` (`amount` masih null — diisi di sprint v0.9.0 Commission).
Response `201` berisi `CustomerResource` seperti `POST /customers`.

### `GET /referrals`

Daftar customer yang direferensikan oleh referrer milik user yang login, plus
status commission masing-masing. `404` kalau user yang login tidak terhubung
ke `Referrer` manapun (`referrers.user_id`). Tidak ada konsep kode referral
yang di-generate/divalidasi di codebase ini — atribusi referrer murni lewat
link `referrers.user_id`, bukan kode.

```json
{
  "success": true,
  "message": "Daftar referral Anda",
  "data": [
    {
      "customer_id": 1,
      "customer_name": "Rina Kusuma",
      "registration_status": "registered",
      "registration_status_label": "Registered",
      "commission_status": "pending",
      "commission_status_label": "Pending",
      "commission_amount": null,
      "registered_at": "2026-08-02T16:10:00+00:00"
    }
  ],
  "meta": []
}
```

---

## Settings (personalisasi per user)

Semua endpoint di bawah ini hanya butuh `auth:sanctum` — tidak ada permission
tambahan, karena setiap user mengatur preferensinya sendiri saja. Logic ada
di `App\Services\ThemeSettingsService` / `LocaleService` /
`DashboardWidgetService`, dipakai bareng oleh endpoint ini dan komponen
Livewire yang sesuai (`Settings\ThemeSettings`, `lang.switch` route,
`Dashboard\WidgetSelector`).

### `GET /settings/theme`

Mengembalikan `primary_color`/`text_color` milik user, atau default
(`#2563eb` / `#1f2937`) kalau belum pernah disimpan.

### `PUT /settings/theme`

Body: `primary_color`, `text_color` (wajib, format hex 6-digit `#rrggbb`).

### `GET /settings/locale`

Mengembalikan `locale` milik user (fallback ke `config('app.locale')`) dan
`supported` (daftar locale yang valid: `id`, `en`).

### `PUT /settings/locale`

Body: `locale` (wajib, salah satu dari `supported`).

### `GET /settings/dashboard-widgets`

Mengembalikan `active` (daftar value widget yang aktif — semua widget kalau
user belum pernah menyimpan pilihan) dan `available` (katalog lengkap widget:
`value` + `label`).

### `PUT /settings/dashboard-widgets`

Body: `widgets` (wajib, array — boleh kosong untuk menyembunyikan semua
widget). Setiap value harus salah satu dari `App\Enums\DashboardWidget`.

---

## Multi-Tenant Reseller (v0.3.2)

Konteks reseller ("saya beroperasi sebagai reseller mana") di-resolve
otomatis oleh middleware `reseller.context`
(`App\Http\Middleware\ResolveResellerContext`) berdasarkan keanggotaan aktif
user di `reseller_users`: 0 keanggotaan → null (ISP admin/staff internal,
lihat semua data), 1 keanggotaan → reseller itu, 2+ keanggotaan → pakai yang
pertama + log warning (multi-reseller switcher belum ada — backlog). Context
ini disimpan di `App\Support\ResellerContext` (container-bound singleton,
bukan session), dipakai `App\Models\Scopes\ResellerScope` untuk otomatis
memfilter query `customers`/`reseller-package-pricing` ke reseller milik
user yang login — tanpa `where()` manual di controller, sama seperti pola
`TenantScope` untuk multi-tenancy.

### `GET/POST /resellers` · `GET/PUT/DELETE /resellers/{reseller}`

Admin-only (permission `resellers.manage` untuk create/update/delete,
`resellers.view` untuk read — hanya `superadmin` yang punya keduanya).
Business logic di `App\Services\ResellerService`. `slug` auto-generate dari
`name` kalau tidak dikirim. `PUT` juga bisa mengubah `status` (`active`/
`suspended`).

### `GET/POST /resellers/{reseller}/users` · `DELETE /resellers/{reseller}/users/{user}`

Kelola staff reseller — diizinkan untuk admin **dan** reseller owner aktif
milik reseller tersebut (bukan staff biasa). `POST` body: `user_id` (harus
user di tenant yang sama), `role` (`owner`/`staff`). `DELETE` **tidak**
menghapus baris `reseller_users` — hanya mengubah `status` jadi `inactive`
(soft-detach, riwayat keanggotaan tetap ada).

### `GET/POST /reseller-package-pricing` · `GET/PUT/DELETE /reseller-package-pricing/{pricing}`

Reseller owner/staff mengelola pricing package milik reseller sendiri;
`reseller_id` **selalu** diambil dari context yang ter-resolve (body
`reseller_id` diabaikan untuk mereka, sama seperti pola atribusi referrer di
`POST /registrations`). ISP admin (tanpa context) **wajib** mengirim
`reseller_id` eksplisit di `POST` — divalidasi harus reseller di tenant yang
sama. `GET` (index) untuk admin bisa difilter opsional lewat query
`?reseller_id=`; tanpa filter, admin melihat pricing lintas-reseller.

Tidak ada `base_package_id`/katalog `packages` di v0.3.2 — field itu belum
ada di codebase manapun. Setiap baris `reseller_package_pricing` adalah
entitas independen; `is_custom` murni flag kategorisasi manual (bundle vs
package biasa), bukan diturunkan dari suatu base package.

```json
{
  "success": true,
  "message": "Package pricing berhasil dibuat",
  "data": {
    "id": 1,
    "reseller_id": 3,
    "reseller_name": "Reseller Demo A",
    "name": "Paket 20 Mbps",
    "description": null,
    "price": 150000.0,
    "is_custom": false,
    "status": "active",
    "status_label": "Active",
    "created_at": "2026-08-03T04:00:00+00:00",
    "updated_at": "2026-08-03T04:00:00+00:00"
  },
  "meta": []
}
```

### Dampak ke `customers`

`POST /customers` dan `POST /registrations` sekarang menerima `reseller_id`
opsional di body — hanya dipakai kalau caller **tidak** punya context
reseller aktif (ISP admin yang eksplisit menugaskan customer ke reseller
tertentu). Untuk reseller owner/staff, `reseller_id` selalu diambil dari
context, mengabaikan apa pun yang dikirim di body. `CustomerResource`
sekarang menyertakan `reseller_id`/`reseller_name`.

---

## CRUD Referrer & Portal Login (v0.9.2)

Permission: `referrers.view`/`referrers.manage`, tier admin saja (`superadmin`/`administrator` — lihat
CLAUDE.md bagian "Two-Tier Admin"). Business logic ada di `App\Services\ReferrerService`, dipakai bareng
oleh endpoint di bawah dan Livewire `Referrers\ReferrerIndex` (`/referrers`).

### `GET /referrers`

List referrer milik tenant yang login, opsional filter `?is_active=`.

### `POST /referrers`

Body: `name`, `phone` (wajib, unik per tenant), `type` (salah satu value `App\Enums\ReferrerType`:
`sales`/`teknisi`/`freelance`/`admin`), `is_active` (opsional, default `true`), `create_login_account`
(opsional boolean).

Kalau `create_login_account=true`: sistem generate `User` baru (nama dari referrer, password acak 16
karakter via `Str::password()`, TIDAK diberi role Spatie apa pun sama sekali — jadi tidak bisa akses panel
admin, hanya portal referrer), lalu `referrers.user_id` ditautkan ke user itu. Response `201` berisi
`{"referrer": {...}, "generated_password": "..."}` — **password mentah hanya muncul di response ini,
sekali, tidak pernah disimpan/di-log/bisa ditampilkan ulang lewat endpoint manapun**. Kalau
`create_login_account` tidak dikirim/`false`, `generated_password` bernilai `null` dan `referrer.user_id`
tetap `null` (referrer dibuat tanpa akun login).

### `GET /referrers/{referrer}` · `PUT /referrers/{referrer}`

`PUT` body: `name`/`phone`/`type`/`is_active` (semua opsional, hanya field yang dikirim yang diubah).

### `POST /referrers/{referrer}/deactivate`

Set `is_active=false`. Tidak ada `DELETE` — referrer dengan riwayat referral/komisi tidak pernah
di-hard-delete, hanya dinonaktifkan.

### `POST /referrers/{referrer}/generate-login-account`

Untuk referrer yang belum punya akun login (`user_id` masih `null`) — generate `User` baru + password acak,
sama seperti alur `create_login_account=true` di atas. Response sama: `{"referrer": {...},
"generated_password": "..."}`. `422` kalau referrer sudah punya akun.

### `POST /referrers/{referrer}/link-user`

Body: `user_id` (wajib, harus user di tenant yang sama, belum tertaut ke referrer lain — dicek di service
DAN dijamin di level DB lewat unique constraint `referrers.user_id`). Menautkan `User` yang SUDAH ADA
sebagai akun login referrer ini — tidak generate password baru, tidak menyentuh password `User` yang sudah
ada. `422` kalau referrer sudah punya akun atau user sudah tertaut ke referrer lain.

### `GET/POST /referrer/login`

**Bukan bagian dari `/api/v1/*`** — endpoint web session (bukan Sanctum), sengaja terpisah dari `/login`
bawaan Fortify (yang terikat ke `email` sebagai username). Body: `phone`, `password`. Berhasil login
(`Auth::guard('web')->login()`, guard yang sama dengan panel admin) redirect ke `/referrer-portal`; gagal
(HP tidak terdaftar, password salah, referrer nonaktif, atau belum punya akun login) menampilkan pesan
generik "Nomor HP atau password salah." di form yang sama (tidak membocorkan bagian mana yang salah).
Dibatasi `throttle:6,1`.

### Portal Referrer (`/referrer-portal`)

Halaman self-service minimal untuk referrer yang login: profil (nama bisa diubah sendiri, nomor HP
read-only karena itu kredensial login), daftar pelanggan yang direferensikan
(`Referrer::referrals()`, dari v0.9.1), dan placeholder "Rekap Komisi — Akan tersedia di update
berikutnya" (logic komisi belum dibangun, menunggu v0.9.3-v0.9.6).

### Middleware pemisah akses

Dua middleware baru menutup celah "tidak ada yang memblokir akses lintas-persona" yang ada sejak v0.1.0:
`admin.panel` (menutup seluruh grup route `/dashboard`, `/customers`, `/invoices`, dst. — mengizinkan user
yang punya permission Spatie apa pun ATAU keanggotaan `reseller_users` aktif, menolak akun portal referrer
murni) dan `referrer.portal` (menutup `/referrer-portal` — hanya user dengan baris `Referrer` aktif yang
tertaut lewat `user_id`). Lihat CLAUDE.md bagian "Two-Tier Admin" dan "CRUD Referrer + Portal Login" untuk
detail teknis lengkap termasuk dua regresi nyata yang ditemukan & diperbaiki saat membangun `admin.panel`.

---

## Bandwidth Profile (v0.14.1)

Fondasi cluster "Profil Paket" (terinspirasi MixRadius V3.2). Permission: `bandwidth_profiles.view`/
`bandwidth_profiles.manage`, tier admin saja (`superadmin`/`administrator`). Business logic di
`App\Services\Network\BandwidthProfileService`, dipakai bareng oleh endpoint di bawah dan Livewire
`Network\BandwidthProfileIndex` (`/bandwidth-profiles`).

### `GET /bandwidth-profiles`

List bandwidth profile milik tenant yang login. Query opsional: `?search=` (cari nama), `?is_active=`,
`?sort_by=`/`?sort_dir=` (default `name`/`asc`).

### `POST /bandwidth-profiles`

Body: `name` (wajib, unik per tenant — soft-deleted tidak menghalangi reuse nama), `upload_min`,
`upload_max` (wajib `>= upload_min`), `download_min`, `download_max` (wajib `>= download_min`) — **semua
dalam Kbps**, `is_active` (opsional, default `true`).

> Satuan Kbps/Mbps di form Livewire murni kenyamanan input admin — dikonversi ke Kbps sebelum sampai ke
> endpoint ini. API sendiri tidak punya konsep satuan, selalu Kbps.

### `GET /bandwidth-profiles/{bandwidth_profile}` · `PUT /bandwidth-profiles/{bandwidth_profile}`

`PUT` body: semua field opsional (`sometimes`), hanya yang dikirim yang diubah.

### `DELETE /bandwidth-profiles/{bandwidth_profile}`

Soft delete (`SoftDeletes`) — bukan hard delete, karena baris ini akan direferensikan Grup Profil/Profil
Hotspot/Profil PPP (v0.14.3+) begitu sub-versi itu dibangun.

```json
{
  "success": true,
  "message": "Bandwidth profile berhasil dibuat",
  "data": {
    "id": 1,
    "name": "10 Mbps",
    "upload_min": 5000,
    "upload_max": 10000,
    "download_min": 5000,
    "download_max": 10000,
    "upload_min_display": "5 Mbps",
    "upload_max_display": "10 Mbps",
    "download_min_display": "5 Mbps",
    "download_max_display": "10 Mbps",
    "is_active": true,
    "created_at": "2026-08-26T09:00:00+00:00",
    "updated_at": "2026-08-26T09:00:00+00:00"
  },
  "meta": []
}
```

`*_display` menampilkan >1000 Kbps sebagai Mbps (`App\Models\BandwidthProfile::formatKbps()`), sisanya
tetap Kbps polos.

---

## IP Pool Pelanggan (v0.14.2)

Same cluster "Profil Paket" as Bandwidth Profile above — **genuinely different concept from `VpnIpPool`
(v0.6.2)**, which is the VPN tunnel address pool between a NAS and BOSS App itself. This is an IP range
allocated to a NAS's own end-customer devices (hotspot/PPP), used starting v0.14.3 (Grup Profil) as a
selectable "Modul IP Pool". Permission: `customer_ip_pools.view`/`customer_ip_pools.manage`, tier admin
only (`superadmin`/`administrator`), same posture as `bandwidth_profiles.*`. Business logic in
`App\Services\Network\CustomerIpPoolService`, shared by the endpoints below and Livewire
`Network\CustomerIpPoolIndex` (`/customer-ip-pools`).

### `GET /customer-ip-pools`

List customer IP pools belonging to the logged-in tenant. Query optional: `?nas_id=` (filter to one NAS's
own pools), `?search=` (name), `?sort_by=`/`?sort_dir=` (default `name`/`asc`).

### `POST /customer-ip-pools`

Body: `nas_id` (required, must be a NAS belonging to the same tenant), `name` (required, **unique per
NAS, not per tenant** — two different NAS may each have a pool with the same name; soft-deleted names
don't block reuse), `network_address` (required, CIDR string e.g. `"192.168.10.0/24"`), `gateway_ip`,
`range_start`, `range_end` (required, valid IPs), `dns_primary`/`dns_secondary` (optional, valid IPs),
`is_active` (optional, default `true`).

Validation beyond plain field types (deliberately "dasar, tidak terlalu ketat" — not a strict
usable-host-only check):
- `range_end` must be `>= range_start` numerically (equal is allowed — a single-address pool is valid).
- `gateway_ip`/`range_start`/`range_end` must fall within `network_address`'s network..broadcast range
  inclusive.
- The range must not overlap any other non-soft-deleted pool **on the same NAS** — overlap is scoped
  per-NAS, not global; the identical range on a different NAS is allowed.

### `GET /customer-ip-pools/{customer_ip_pool}` · `PUT /customer-ip-pools/{customer_ip_pool}`

`PUT` body: all fields optional (`sometimes`), only what's sent is changed. Changing `nas_id` re-runs the
unique-name/overlap checks against the NEW NAS, not the old one.

### `DELETE /customer-ip-pools/{customer_ip_pool}`

Soft delete — same "will be referenced by Grup Profil (v0.14.3+)" reasoning as Bandwidth Profile above.

```json
{
  "success": true,
  "message": "Customer IP pool berhasil dibuat",
  "data": {
    "id": 1,
    "nas_id": 3,
    "nas_name": "NAS-001",
    "name": "Pool Utama",
    "network_address": "192.168.10.0/24",
    "gateway_ip": "192.168.10.1",
    "range_start": "192.168.10.10",
    "range_end": "192.168.10.200",
    "dns_primary": "8.8.8.8",
    "dns_secondary": "8.8.4.4",
    "is_active": true,
    "created_at": "2026-08-27T12:00:00+00:00",
    "updated_at": "2026-08-27T12:00:00+00:00"
  },
  "meta": []
}
```

`nas_name` is only present when the `nas` relation was eager-loaded (every endpoint above does this).

---

## Payment Gateway (Xendit, v0.3.5)

> Catatan: endpoint `invoices` (CRUD, generate, transisi status) dari v0.3.4
> belum punya bagian tersendiri di dokumen ini — gap dokumentasi lama, bukan
> baru. Bagian ini hanya mencakup endpoint payment/webhook yang ditambahkan
> di v0.3.5.

Permission: sama seperti invoice induknya (`invoice.view` untuk baca,
`invoice.manage` untuk membuat payment attempt). Nested di bawah
`/invoices/{invoice}`, di dalam grup middleware `reseller.context`.

### `GET /invoices/{invoice}/payments`

Daftar `Payment` (attempt pembayaran) milik satu invoice.

### `POST /invoices/{invoice}/payments`

Body: `channel_type` (wajib — salah satu `code` yang ada di katalog
`payment_gateway_channels`, mis. `BRI_VA`/`QRIS`/`XENDIT_INVOICE`; **bukan**
lagi 3 nilai tetap — lihat "Fase H Amendment" di `docs/ROADMAP.md`). Channel
harus `enabled` (diatur admin lewat halaman Pengaturan > Payment Gateway,
`/settings/payment-gateway`, tidak ada endpoint API terpisah untuk ini di
sprint ini) — channel yang ada di katalog tapi disabled ditolak dengan pesan
jelas, bukan `422` biasa. Memanggil Xendit
(`App\Services\Payment\XenditGatewayService`) untuk membuat instrumen
pembayaran, pakai `invoice.invoice_number` sebagai `external_id` (bukan `id`
numerik). Ditolak (`422`/exception) kalau invoice sudah `paid`/`cancelled`,
atau kalau channel-nya kategori `ewallet`/`retail_outlet`/`credit_card`
(terdaftar di katalog untuk UI tapi belum ada integrasi API Xendit-nya).
**Tidak** langsung menandai invoice lunas — itu hanya terjadi lewat webhook
di bawah (atau lewat endpoint manual `PATCH /invoices/{invoice}/paid` yang
sudah ada sejak v0.3.4, dipertahankan apa adanya per keputusan eksplisit,
lihat CLAUDE.md).

### `POST /webhooks/xendit`

**Publik** (di luar `auth:sanctum`, throttle `60,1`) — dipanggil Xendit,
bukan client BOSS App. Autentikasi lewat header `x-callback-token`
dibandingkan (`hash_equals`) terhadap token yang tersimpan di
`payment_gateway_settings` (diatur lewat halaman Pengaturan > Payment
Gateway, bukan lagi `XENDIT_CALLBACK_TOKEN` di `.env` — lihat "Fase H
Amendment" di `docs/ROADMAP.md`) — bukan HMAC.
Selalu balas `200` apa pun hasil pemrosesan internalnya (signature invalid,
duplicate event, amount mismatch, invoice tidak ditemukan, atau berhasil
diterapkan) supaya Xendit berhenti retry; hasil sebenarnya cuma bisa dilihat
lewat `payment_webhook_logs`/laporan rekonsiliasi, bukan dari response
webhook itu sendiri.

Validasi berurutan sebelum invoice ditandai lunas: signature → idempotency
(`xendit_event_id` unik) → `payload.amount` harus **persis sama** dengan
`invoice.grand_total` (bukan `>=`, tidak ada partial payment di sprint ini).
Kalau semua lolos, baru `App\Services\InvoiceService::markPaid()` dipanggil.

Sandbox-only di sprint ini — `XenditGatewayService` menolak beroperasi kalau
`XENDIT_IS_PRODUCTION=true` sementara `APP_ENV` server bukan `production`.

## WhatsApp Gateway (Baileys, v0.4.0)

Semua endpoint di bawah (kecuali webhook) ada di dalam grup middleware
`reseller.context` — scoping otomatis lewat `BelongsToResellerScope` pada
model `WhatsappSession`/`WhatsappMessageTemplate`/`WhatsappMessageLog`:
reseller (owner/staff, via `reseller_users` membership) hanya melihat
sesi/template/antrian miliknya sendiri; ISP admin (permission
`whatsapp_gateway.view`/`.manage`) melihat semuanya termasuk sesi "direct".

### `GET /whatsapp/sessions` · `GET /whatsapp/sessions/{session}`

Daftar/detail sesi WhatsApp. `status`: `qr_pending`/`connected`/
`disconnected`/`logged_out`.

### `POST /whatsapp/sessions/{session}/refresh-qr`

Minta QR code baru dari Node gateway (`GET /sessions/{key}/qr` di sisi
Node, HMAC-signed). Kalau sesi berstatus `logged_out`, Node menghapus
`auth_state` sesi itu dulu sebelum membuat pairing baru.

### `GET /whatsapp/templates` · `PUT /whatsapp/templates/{eventType}` · `DELETE /whatsapp/templates/{eventType}`

`{eventType}`: salah satu dari `invoice_due_reminder`/`payment_received`/
`customer_registered`/`customer_suspended_reminder`. `PUT` meng-upsert
template milik scope acting user (reseller override kalau ada context,
default ISP-level kalau admin tanpa context). `DELETE` (reseller-only)
mereset ke default ISP-level (hapus baris override).

Variabel template: `{customer_name}`, `{customer_id}`, `{invoice_number}`,
`{due_date}`, `{total_amount}`, `{package_name}`, `{company_name}`,
`{payment_link}` (khusus `invoice_due_reminder`, di-generate on-demand
lewat `PaymentService::createPaymentFor()` channel `XENDIT_INVOICE` — bisa
kosong kalau channel itu belum aktif). Variabel yang tidak berlaku untuk
suatu `event_type` dikosongkan, bukan dibiarkan sebagai placeholder mentah.

### `GET /whatsapp/message-logs` · `POST /whatsapp/message-logs/{log}/retry`

Antrian pesan (`status`: `queued`/`sent`/`failed`/`delivered`), filter
`?status=`/`?reseller_id=` (khusus admin). `retry` hanya berlaku untuk log
berstatus `failed` — reset ke `queued`, `attempts` di-nol-kan, dispatch
ulang ke queue `whatsapp-{session_key}`.

### `GET /settings/whatsapp-gateway` · `PUT /settings/whatsapp-gateway`

Admin-only (`whatsapp_gateway_settings.view`/`.manage`, di luar grup
`reseller.context`) — rate limit global: delay antar pesan, batch size, jeda
antar batch, jadwal batch reminder harian (`daily_schedule_times`).

### `POST /whatsapp/webhook/session-status`

**Publik** (di luar `auth:sanctum`, throttle `60,1`) — dipanggil
`whatsapp-gateway` (Node), bukan client BOSS App, setiap event
`connection.update` (QR baru/connected/disconnected/logged_out).
Autentikasi lewat HMAC-SHA256 (`App\Support\WhatsappHmac`, header
`X-Whatsapp-Signature`/`X-Whatsapp-Timestamp`, toleransi 5 menit) — beda
dari static-token Xendit. Selalu balas `200` apa pun hasilnya, sama seperti
`/webhooks/xendit`.

## Installation / Work Order (v0.5.0)

Semua endpoint di bawah ada di dalam grup middleware `reseller.context` —
scoping otomatis lewat `BelongsToResellerScope` pada model
`Odp`/`Technician`/`WorkOrder`: reseller (owner/staff, via `reseller_users`
membership) hanya melihat ODP/teknisi/work order miliknya sendiri; ISP admin
(permission `odps.*`/`technicians.*`/`work_orders.*`) melihat semuanya
termasuk yang direct (tanpa reseller).

### `GET/POST /odps` · `GET /odps/{odp}` · `PUT /odps/{odp}` · `DELETE /odps/{odp}`

CRUD katalog ODP. `POST` otomatis men-generate `total_ports` baris
`odp_ports` (status `available`) lewat `Odp::provisionPorts()` — tanpa ini
ODP baru tidak akan pernah punya port yang bisa dicari
`OdpLocatorService`. `reseller_id` di body hanya dipakai kalau caller tidak
punya reseller context aktif (ISP admin membuat ODP untuk reseller
tertentu, atau kosong = ODP direct).

### `GET/POST /technicians` · `GET /technicians/{technician}`

CRUD dasar data teknisi (`user_id` wajib menunjuk `users` row yang sudah
ada — tidak ada pembuatan user baru dari endpoint ini).

### `GET /work-orders` · `GET /work-orders/{work_order}`

Daftar/detail work order, filter `?status=`.

### `POST /work-orders`

Body: `subscription_id`. Pembuatan manual oleh admin/CS — jalur yang sama
persis dengan trigger otomatis di bawah, cuma subscription-nya dipilih
eksplisit lewat body, bukan lewat parameter route.

### `POST /subscriptions/{subscription}/work-order`

Trigger otomatis dari flow sales/subscription. Keduanya memanggil
`WorkOrderService::createFromSubscription()`: cari port ODP terdekat yang
available lewat `OdpLocatorService` (Haversine, di-scope ke reseller yang
sama dengan subscription atau direct) — kalau ketemu, port di-reserve dan
status jadi `pending_verification`; kalau tidak, status jadi
`odp_unavailable`.

### `POST /work-orders/{work_order}/verify`

Body: `equipment_ready` (boolean). Transisi ke `ready` hanya kalau
`equipment_ready=true` DAN port ODP sudah ter-reserve — kalau false, flag
tersimpan tapi status tetap `pending_verification` (bukan error).

### `POST /work-orders/{work_order}/assign`

Body: `technician_id`. Transisi ke `assigned`.

### `POST /work-orders/{work_order}/start`

Transisi ke `in_progress`.

### `POST /work-orders/{work_order}/photos`

Multipart: `type` (`odp`/`ont_device`/`signal_strength`/`house_front`) +
`file` (image, max 10MB). Satu foto per jenis per work order — upload ulang
jenis yang sama mengganti file lama (dihapus dari disk), bukan menambah
baris baru. Disimpan di disk `local` (private, `storage/app/private/` di
Laravel 12 — bukan `storage/app/public`, tidak bisa diakses publik).

### `POST /work-orders/{work_order}/devices`

Body: `device_type` (`ont`/`router`/`ap`), `mac_address`, `serial_number`.
Bisa dipanggil berkali-kali (tidak ada batas jumlah device per work order).

### `PATCH /work-orders/{work_order}/devices/{device}/provisioning` (v0.7.5)

**Jalur input sementara (bridge), BUKAN form self-service teknisi** — CS/
admin yang input manual dari info yang direlai teknisi lewat telepon/WA
personal. UI teknisi lapangan sesungguhnya (WhatsApp bot 2 arah atau Mobile
App) masih backlog terpisah (v0.11.0 dkk) — lihat catatan di
`ProvisionWorkOrderDeviceRequest`.

Body (keduanya opsional, **minimal salah satu wajib diisi tiap call**,
tapi TIDAK saling mewajibkan satu sama lain seperti endpoint v0.7.4 —
partial update sungguhan, bisa isi SSID sekarang dan password menyusul di
call terpisah tanpa menghapus yang sudah tersimpan):
- `ssid` (string, maks 32 karakter)
- `wifi_password` (string, 8-63 karakter — disimpan encrypted di
  `work_order_devices.wifi_password`, kredensial asli bukan hash, karena
  memang perlu dikirim ke device nanti)

Endpoint ini **hanya mencatat**, tidak memicu push ke device sama sekali —
push sungguhan terjadi belakangan lewat
`CpeBindingService::provisionWifiIfPending()` begitu device ini dikenal
GenieACS (baik langsung saat binding, atau lewat reconciliation job kalau
saat binding device masih `pending_first_connect`). Kalau device sudah
lebih dulu dikenal GenieACS SEBELUM endpoint ini dipanggil (mis. teknisi
telepon CS setelah instalasi kelar), push tidak terjadi otomatis lagi
(tidak ada trigger baru) — CS pakai tombol "Ganti WiFi" manual dari v0.7.4
di `/cpe-devices` untuk kasus itu.

### `POST /work-orders/{work_order}/complete`

Transisi ke `completed`, port ODP jadi `used`. **Ditolak** (422,
`IncompleteWorkOrderException`) kalau belum ada keempat jenis foto atau
belum ada minimal 1 device tercatat — dicek SETELAH legalitas transisi
status sendiri (state machine tetap prioritas pertama).

### `POST /work-orders/{work_order}/cancel`

Transisi ke `cancelled` dari status non-terminal manapun. Port ODP yang
sempat `reserved`/`used` dikembalikan ke `available`.

## FreeRADIUS Core & NAS Management (v0.6.1)

Semua endpoint di bawah ada di dalam grup middleware `reseller.context` —
scoping otomatis lewat `BelongsToResellerScope` pada model `Nas`: reseller
(owner/staff, via `reseller_users` membership) hanya melihat/mengelola NAS
miliknya sendiri; ISP admin (permission `nas.*`) melihat semuanya termasuk
yang direct (tanpa reseller). Tabel `nas` ini berada di `boss_db` — bukan
tabel `nas` bawaan FreeRADIUS sendiri di `radius_db` (nama sama, database
beda, lihat CLAUDE.md).

### `GET/POST /nas` · `GET /nas/{nas}` · `PUT /nas/{nas}` · `DELETE /nas/{nas}`

CRUD inventaris NAS (router Mikrotik). `mikrotik_ip` TIDAK bisa diisi manual
lewat endpoint REST ini (bisa lewat UI `/nas`, lihat bawah).
`auth_port`/`acct_port` **otomatis teralokasi unik** saat create (v0.6.5,
`NasPortAllocatorService`) — tidak pernah diterima sebagai input dan tidak
pernah berubah setelah itu. `coa_port` **bukan** bagian dari alokasi itu —
tetap kolom biasa yang bisa diisi manual, default 3799 (RFC 5176), karena
CoA divalidasi oleh RouterOS berdasarkan `/radius incoming port=` yang
sifatnya satu pengaturan per-router, bukan per-server-RADIUS (lihat
CLAUDE.md v0.6.5 untuk detail temuannya). Response TIDAK PERNAH
menyertakan nilai asli `api_password`/`radius_secret` — hanya flag
`has_api_password`/`has_radius_secret` (pola sama dengan
`payment_gateway_settings`).

### `POST /nas/{nas}/test-connection`

Ping ke Mikrotik API NAS ini (bukan RADIUS, bukan ICMP) lewat
`RouterOsGateway` (evilfreelancer/routeros-api-php di baliknya), lalu
menyimpan `status` (`online`/`offline`) + `last_ping_at`. **Ditolak** (422,
`NasNotProvisionedException`) kalau `mikrotik_ip` masih kosong.

### `POST /nas/{nas}/disconnect` (v0.6.5)

Body wajib: `username`. Mengirim RADIUS Disconnect-Request (RFC 5176) ke
NAS lewat `CoaService` — isolir instan pelanggan menunggak. Menyasar akun
VPN OpenVPN/WireGuard **aktif** paling baru milik NAS ini (`internal_ip`-
nya); **ditolak** (422, `CoaUnavailableException`) kalau tidak ada akun
aktif dengan salah satu dari kedua protokol itu — L2TP/IPsec sengaja tidak
didukung (known limitation, lihat CLAUDE.md). Response `data.ok` (boolean)
+ `data.raw` (output mentah `radclient`) — timeout 15 detik
(`CoaTimeoutException`, 422) kalau container `freeradius` tidak merespons.
Lihat CLAUDE.md v0.6.5 untuk kenapa eksekusi sesungguhnya harus terjadi di
dalam container `freeradius` (bukan `boss-app`), dan keterbatasan yang
sudah diketahui pada topologi multi-node (v0.6.4).

### `POST /nas/{nas}/provision-api-user` (v0.6.5, amendment)

Body wajib: `admin_username`, `admin_password` — kredensial admin ASLI
router (full akses), dipakai **sekali pakai** untuk request ini saja, TIDAK
PERNAH disimpan ke database atau log. Membuat/memperbarui user API khusus
BOSS App di router (`boss-app-api-{nas_id}`, grup `boss-app-api`, policy
`read,api,password` + deny selebihnya — lihat CLAUDE.md v0.6.5), lalu
meng-update `nas.api_username`/`api_password` ke kredensial baru itu.
**Ini satu-satunya jalur yang menulis kolom itu** — `POST /nas/{nas}` dan
`PUT /nas/{nas}` (`StoreNasRequest`/`UpdateNasRequest`) juga masih
menerima `api_username`/`api_password` untuk kasus isi manual, tapi
endpoint ini adalah jalur yang direkomendasikan. **Ditolak** (422,
`NasApiUserProvisioningException`) kalau router menolak kredensial admin
atau perintah provisioning gagal — tidak ada perubahan ke `nas` row kalau
gagal.

## VPN Multi-Protokol (OpenVPN v0.6.2, WireGuard/L2TP-IPsec v0.6.3)

Endpoint di bawah juga ada di dalam grup middleware `reseller.context`.
`vpn_accounts` tidak punya `reseller_id`/`tenant_id` sendiri — otorisasi
diturunkan dari NAS pemiliknya (`NasPolicy::manage` terhadap
`$vpn_account->nas`), pola sama dengan `odp_ports`/`work_order_photos`
(v0.5.0).

### `POST /nas/{nas}/vpn-account`

Body opsional: `protocol` (`openvpn` default, `wireguard`, atau
`l2tp_ipsec`). Provisioning akun VPN baru untuk sebuah NAS: alokasikan
`internal_ip` dari `vpn_ip_pool` milik `vpn_servers` protokol yang sesuai
dan aktif (race-condition-safe lewat `lockForUpdate()`), lalu jalankan
langkah spesifik protokol:
- **openvpn** — `easyrsa build-client-full` terhadap PKI bersama, simpan
  `cert_serial`, tulis file client-config-dir (`ifconfig-push`).
- **wireguard** — generate keypair (`wg genkey`/`wg pubkey` dari
  `boss-app`), simpan `public_key`, tulis file peer ke volume bersama
  (container `wireguard` menerapkannya lewat reconcile loop tiap ~10
  detik, lihat CLAUDE.md). Response menyertakan `wireguard_private_key`
  **HANYA SEKALI** saat ini — BOSS App tidak pernah menyimpannya, tidak
  bisa diambil ulang lewat endpoint manapun setelah ini.
- **l2tp_ipsec** — generate password acak, simpan ter-enkripsi di
  `vpn_accounts.password`, regenerate seluruh file `chap-secrets` dari DB.

**Ditolak** (422) kalau NAS sudah punya akun VPN aktif untuk protokol yang
sama, pool IP habis (`VpnIpPoolExhaustedException`), atau prasyarat
protokol belum siap (`VpnProvisioningException` — mis. PKI `openvpn` belum
bootstrap). Tidak mengisi `nas.mikrotik_ip` — itu langkah manual terpisah
setelah NAS benar-benar connect lewat tunnel dan
`POST /nas/{nas}/test-connection` berhasil.

### `POST /vpn-accounts/{vpn_account}/revoke`

Deprovisioning spesifik protokol (revoke sertifikat + `gen-crl` untuk
OpenVPN, hapus file peer untuk WireGuard, hapus baris dari `chap-secrets`
untuk L2TP/IPsec), lalu bebaskan `internal_ip` kembali ke pool. **Tidak**
ada satu pun jalur yang me-restart/reload daemon-nya — OpenVPN membaca CRL
ulang otomatis per koneksi baru, `chap-secrets` dibaca `pppd` fresh per
percobaan (di-spawn baru oleh `xl2tpd` tiap kali), WireGuard reconcile loop
mengambil perubahan di siklus berikutnya (maks ~10 detik, bukan instan).
Sesi yang SUDAH terkoneksi (OpenVPN atau L2TP) tidak langsung diputus paksa
oleh endpoint ini — known limitation, dicatat sebagai backlog.

## Manajemen NAS (v0.6.3, halaman web, bukan REST API)

`GET /nas` (`web.nas.index`, `App\Livewire\Network\NasIndex`) — UI list +
create/edit NAS yang v0.6.1 sengaja tidak dibangun (API-only sprint itu).
Field form: nama, reseller (admin only — reseller owner/staff otomatis
terikat ke reseller miliknya, field disembunyikan), zona waktu, IP router
(bisa diisi manual, terkunci read-only begitu NAS punya `vpn_accounts`
aktif), port/username/password API (password masked, blank submit tidak
menghapus nilai tersimpan), secret RADIUS (masked, wajib diisi saat create),
deskripsi. Auth/Accounting/CoA Port ditampilkan read-only (v0.6.5: nilai
NYATA milik NAS ini — auth/acct teralokasi otomatis & unik saat create,
tidak pernah berubah setelahnya; coa_port default 3799, editable lewat
REST API tapi belum lewat form ini). Tombol "Tes Koneksi" memakai
`RouterOsGateway` yang sama dengan endpoint REST
`POST /nas/{nas}/test-connection` (v0.6.1) tapi terhadap nilai form yang
sedang diketik, bukan nilai tersimpan — lihat CLAUDE.md untuk detail kenapa
`NasService::testConnection()` sendiri tidak langsung dipakai di sini, dan
untuk bug nyata yang pernah ditemukan di sini (tes sukses dengan password
BEDA dari yang tersimpan tidak pernah menyimpan ulang kredensial yang
benar — sudah diperbaiki).
REST API `/api/v1/nas` (v0.6.1) tetap ada dan tidak berubah — halaman ini
cuma UI tambahan, bukan pengganti.

## Script Generator (v0.6.3, halaman web, bukan REST API)

`GET /vpn-script-generator` (`web.vpn-script-generator.index`,
`App\Livewire\Network\VpnScriptGenerator`) — bukan endpoint REST, halaman
Livewire biasa di dalam layout web utama. 2 tab:

- **VPN Script**: pilih NAS + versi RouterOS (6/7) + protokol (WireGuard
  disable otomatis kalau RouterOS 6.x dipilih — fitur itu cuma ada di
  RouterOS 7+). Kalau NAS belum punya akun aktif untuk protokol itu,
  provisioning otomatis dijalankan lewat `VpnScriptService` (memanggil
  `VpnProvisioningService` yang sama dengan endpoint API di atas) sebelum
  script digenerate. Untuk WireGuard, generate ulang HANYA berhasil kalau
  akunnya baru saja diprovisioning di request yang sama (private key tidak
  pernah disimpan) — kalau sudah ada akun aktif dari sebelumnya, harus
  revoke dulu lewat API baru generate ulang.
- **RADIUS Script**: generate `/radius add` SAJA (registrasi FreeRADIUS
  sebagai server RADIUS di NAS ini). v0.6.5: memakai `nas.auth_port`/
  `acct_port` NYATA milik NAS ini (dynamic virtual server FreeRADIUS),
  bukan lagi port default 1812/1813 bersama — diverifikasi nyata terhadap
  test-x86-bajastu (Access-Accept genuine lewat port dinamisnya). **Murni
  read-only** — tidak lagi menyentuh `nas.api_username`/`api_password`
  atau `/user`/`/user group` di router sama sekali (amendment: versi
  sebelumnya merotate kredensial API di setiap panggilan, bug nyata yang
  jadi penyebab NAS "offline sendiri" berulang — lihat CLAUDE.md v0.6.5).
  User API Mikrotik sekarang dibuat/diperbarui lewat aksi terpisah,
  `POST /nas/{nas}/provision-api-user` (lihat di atas).

Script yang dihasilkan idempotent (hapus interface/route/rule lama dulu)
dan mengisolasi routing (routing-mark untuk OpenVPN/L2TP, `allowed-address`
bawaan untuk WireGuard) — NAS tidak pernah dapat default route lewat
tunnel. **Belum diuji terhadap perangkat Mikrotik sungguhan** (tidak ada
di environment ini).

## GenieACS Core (v0.7.1)

Endpoint di bawah ada di dalam grup middleware `reseller.context` — scoping
otomatis lewat `BelongsToResellerScope` pada model `CpeDevice`, pola sama
dengan `nas`/`odps`: reseller (owner/staff) hanya melihat perangkat CPE
miliknya sendiri; ISP admin (permission `cpe_devices.view`/`.manage`)
melihat semuanya termasuk yang direct (tanpa reseller). **Baris `cpe_devices`
itu sendiri tidak punya endpoint create/update/delete** — satu-satunya cara
sebuah baris tercipta adalah otomatis lewat
`CpeBindingService::bindFromWorkOrder()`, dipanggil dari hook di
`WorkOrderService::complete()` (best-effort — kegagalan binding tidak pernah
menggagalkan penyelesaian work order itu sendiri). Tidak ada input manual
admin untuk binding device, sesuai keputusan yang dikunci saat planning
sprint ini. **Aksi remote ke device yang sudah ter-bind** (reboot, ganti
WiFi) ada mulai v0.7.4 — lihat "GenieACS Remote Actions (v0.7.4)" di bawah.

### `GET /cpe-devices` · `GET /cpe-devices/{cpe_device}`

List (paginated, `?per_page=`) dan detail satu perangkat CPE. Response
lewat `CpeDeviceResource`: `customer_id`/`customer_name`, `reseller_id`/
`reseller_name`, `genieacs_device_id` (format
`OUI-ProductClass-SerialNumber`, `null` kalau perangkat belum pernah
inform ke GenieACS), `manufacturer`, `model_name`, `serial_number`,
`tr069_root` (`InternetGatewayDevice` atau `Device` — TR-098 vs TR-181,
`null` kalau belum diketahui), `status` (`pending_first_connect` /
`online` / `offline`) + `status_label`, `last_inform_at`, `bound_at`
(kapan proses binding terjadi — dipakai v0.7.4 nanti sebagai gerbang
provisioning otomatis), `created_at`.

**Matching binding memakai serial number, bukan MAC address** — MAC tidak
punya path parameter TR-069 yang sama di semua vendor (baru dipetakan
per-vendor di v0.7.2), sedangkan `_deviceId._SerialNumber` selalu tersedia
dari setiap Inform RPC (field wajib TR-069, bukan opsional). Kalau device
hasil scan teknisi belum pernah inform ke GenieACS sama sekali saat work
order selesai, baris `cpe_devices` tetap dibuat dengan
`genieacs_device_id` null dan `status = pending_first_connect` (tidak
gagal keras) — command terjadwal `ReconcileCpeDevices` (tiap 5 menit)
mencoba mencocokkan ulang berdasarkan serial number begitu device itu
benar-benar online.

## GenieACS Vendor Parameter Mapping (v0.7.2)

Platform-level catalog (superadmin-only, permission `cpe_parameter_maps.view`/
`.manage`, **tidak** ada carve-out reseller seperti `nas`/`odps`) yang
memetakan path parameter TR-069 per vendor/model (`oui` + `product_class`,
persis nilai `_deviceId._OUI`/`_deviceId._ProductClass` dari GenieACS) ke
nilai dunia-nyata lewat formula konversi (`raw`, `linear`, atau
`sff8472_optical_log10` — lihat `App\Enums\CpeParameterConversionFormula`).
Sebuah baris punya `verified_at`/`verified_against_device_id` **hanya**
kalau sudah benar-benar dicek terhadap device nyata (lewat endpoint
`verify` di bawah, atau seeder untuk baris pertama yang sudah diverifikasi
manual) — field ini sengaja tidak bisa diisi langsung lewat `store`/
`update`, mengedit definisi (path/formula/params) sebuah baris otomatis
menurunkannya kembali ke belum-terverifikasi.

### `GET /cpe-parameter-maps` · `GET /cpe-parameter-maps/{cpe_parameter_map}`

List (filter opsional `?oui=`/`?product_class=`) dan detail satu mapping.

### `POST /cpe-parameter-maps` · `PUT /cpe-parameter-maps/{cpe_parameter_map}` · `DELETE /cpe-parameter-maps/{cpe_parameter_map}`

CRUD standar. `oui`+`product_class`+`parameter_key` unik bersama.

### `POST /cpe-parameter-maps/{cpe_parameter_map}/verify`

Body: `{"device_id": "OUI-ProductClass-SerialNumber"}`. Menandai baris
terverifikasi memakai waktu saat ini + device id yang benar-benar dites —
tidak menerima timestamp/device id dari input langsung.

### `GET /cpe-parameter-maps/resolve/{genieacs_device_id}`

Endpoint pembuktian end-to-end: ambil device nyata dari GenieACS lewat
`GenieAcsClientService`, cocokkan OUI+ProductClass-nya ke baris
`cpe_parameter_maps` yang ada, tarik raw value dari parameter tree device
itu, konversi lewat `ParameterConversionService`. Per parameter_key,
response berisi `raw_value`, `value` (hasil konversi, `null` kalau path
belum ada di tree device — biasanya berarti device itu belum pernah
di-`refreshObject` sampai kedalaman itu), `verified`, dan `error` (kalau
ada). Contoh nyata pertama yang sudah diverifikasi: ZTE F663NV3.1
(`oui=F86CE1`, `product_class=F663NV3a`) — `rx_power_dbm`/`tx_power_dbm`
lewat `InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.
{RXPower,TXPower}`, formula `sff8472_optical_log10`.

## GenieACS Remote Actions (v0.7.4)

**Status jujur, bukan basa-basi**: task di bawah selalu berhasil "terkirim"
(masuk antrean GenieACS) selama device pernah punya `genieacs_device_id` dan
mapping parameter yang relevan ada — itu **bukan** konfirmasi bahwa device
sudah benar-benar mengeksekusinya. `status=delivered` berarti task
ter-enqueue di `genieacs-nbi` (task dokumen nyata dengan `_id`), titik.
GenieACS SELALU mencoba Connection Request (`?connection_request`) supaya
device dapat perintah ini instan kalau memang bisa dijangkau — tapi jalur
itu (v0.7.3) **belum dikonfirmasi jalan end-to-end** terhadap hardware asli
(lihat CLAUDE.md "GenieACS Connection Request Routing (v0.7.3)"), jadi
respons endpoint ini TIDAK PERNAH mengklaim "berhasil sekarang juga" —
selalu "perintah terkirim, akan diterapkan saat device terhubung
berikutnya". Kalau Connection Request kebetulan berhasil, perintah memang
bisa langsung berlaku — endpoint ini cuma tidak menjanjikannya.

Otorisasi: `manage()` di `CpeDevicePolicy` — permission `cpe_devices.manage`
(admin, akses semua device termasuk direct), atau membership
`reseller_users` aktif (owner ATAU staff) untuk device milik reseller itu
sendiri. Sama pola dengan `nas`/`odps` — bukan permission per-reseller.

### `POST /cpe-devices/{cpe_device}/actions/reboot`

Tidak ada body. Menulis `cpe_action_logs` (status `queued`), kirim task
`{"name": "reboot"}` ke GenieACS lewat `GenieAcsClientService::sendTask()`,
update ke `delivered` (task ter-enqueue) atau `failed` (device belum punya
`genieacs_device_id`, atau GenieACS menolak request-nya — bukan sekadar
Connection Request-nya gagal, itu tidak masalah). Response: `CpeActionLogResource`
+ `message` yang jujur soal status ini.

### `POST /cpe-devices/{cpe_device}/actions/wifi`

Body: `ssid` (opsional, string, maks 32 karakter) dan/atau `password`
(opsional, string, 8-63 karakter — panjang standar WPA-PSK) — **minimal
salah satu wajib diisi** (`required_without` satu sama lain). Path parameter
TR-069 yang dipakai diambil dari `cpe_parameter_maps` (`wifi_ssid`/
`wifi_password`), dicocokkan lewat OUI+ProductClass device itu — kalau
mapping belum ada untuk model device tersebut, log tetap tercatat tapi
`status=failed` dengan `failed_reason` yang jelas (bukan exception mentah ke
caller). Password **tidak pernah** disimpan plaintext di `parameters` —
hanya fingerprint sha256 (`new_password_fingerprint`) + flag
`password_changed`, murni buat audit "apakah ini sama dengan perubahan
sebelumnya", bukan penyimpanan kredensial yang bisa dibaca ulang. Kalau
`ssid` DAN `password` sama-sama diisi, keduanya dikirim sebagai SATU task
GenieACS (`setParameterValues` dengan 2 entry) — bukan dua task terpisah —
supaya tidak ada risiko dua field itu mendarat di dua sesi Inform berbeda.

### `GET /cpe-devices/{cpe_device}/actions`

Riwayat aksi (paginated, `?per_page=`) untuk satu device, terbaru dulu.
Response `CpeActionLogResource`: `action_type`/`action_type_label`,
`parameters` (redacted seperti di atas), `genieacs_task_id`, `status`/
`status_label`, `failed_reason`, `performed_by_name`, `created_at`,
`completed_at` (kapan log ini mencapai status akhir `delivered`/`failed` —
**bukan** kapan device selesai mengeksekusi, BOSS App tidak punya cara
mengetahui itu sprint ini). `performed_by_name` bisa berisi **"Sistem
(auto-provisioning)"** (bukan nama user) — lihat "GenieACS Auto-Provisioning
(v0.7.5)" di bawah.

## GenieACS Auto-Provisioning (v0.7.5)

**Bukan endpoint REST baru** (selain satu bridge endpoint di section
Installation di atas — `PATCH /work-orders/{work_order}/devices/{device}/
provisioning`) — ini reuse penuh `CpeActionService` (v0.7.4) lewat hook baru
di `App\Services\Network\CpeBindingService`, dipanggil otomatis dari dua
titik: `bindFromWorkOrder()` (saat work order selesai dan device langsung
dikenal GenieACS) dan `reconcilePending()` (saat job terjadwal `cpe:reconcile`
berhasil mencocokkan device `pending_first_connect`).

**Tidak ada actor manusia untuk aksi ini** — `cpe_action_logs.performed_by`
jadi nullable khusus untuk kasus ini (dikonfirmasi Agung: lebih jujur
daripada bikin user sistem palsu). Baris log otomatis punya
`parameters.triggered_by` bernilai `auto_provisioning_binding` atau
`auto_provisioning_reconciliation`, dan `performed_by_name` di
`CpeActionLogResource`/riwayat aksi UI menampilkan **"Sistem
(auto-provisioning)"**, bukan nama user.

**Guard anti-duplikat**: `cpe_devices.wifi_provisioned_at` (nullable,
di-set HANYA saat `CpeActionLog` hasil push ini berstatus `delivered` —
bukan sekadar "sudah dicoba") — kedua titik hook di atas sama-sama
mengecek ini masih `null` sebelum push, dan sebuah `CpeDevice` cuma
pernah melewati salah satu dari dua titik hook itu sekali (device yang
sudah `online` tidak pernah disentuh `reconcilePending()` lagi). Kalau
push pertama gagal (mis. `cpe_parameter_maps` belum ada untuk model
device itu), `wifi_provisioned_at` sengaja dibiarkan `null` — **tidak ada
retry otomatis** untuk device yang sudah `online`, CS perlu push manual
lewat tombol "Ganti WiFi" (v0.7.4) di `/cpe-devices`.

## GenieACS Connected Clients (v0.7.6)

Baca object TR-069 `LANDevice.{i}.Hosts.Host.{n}` (client yang terhubung ke
WiFi/LAN device) — **read-only murni dari sisi API**, tidak ada endpoint
yang memicu sync. Data diisi oleh command terjadwal
`cpe:sync-connected-hosts` (tiap 5 menit, pola sama `cpe:reconcile` v0.7.1),
lewat `App\Services\Network\CpeConnectedHostsService::syncFromGenieAcs()` —
membaca data yang **sudah tersimpan** di GenieACS, tidak pernah memicu
`refreshObject`/Connection Request sendiri.

**Bukan snapshot, histori** — satu baris per `(cpe_device_id, mac_address)`
di `cpe_connected_hosts`, tidak pernah satu baris per poll (menghindari
tabel membengkak tak terkendali). `first_seen_at` cuma diisi sekali saat
MAC address itu pertama kali muncul; `last_seen_at` di-update tiap kali
MAC itu masih muncul di poll; `is_active` jadi `false` (baris **tidak
dihapus**) begitu MAC yang sebelumnya tercatat tidak muncul lagi di satu
poll. `hostname`/`ip_address` hanya ditimpa kalau poll saat itu punya
nilai baru — device yang sesaat melaporkan `HostName` kosong tidak
menghapus nama yang sudah diketahui sebelumnya.

**Nomor instance `Host.{n}` TERBUKTI tidak stabil/tidak berurutan di
hardware nyata** (dikonfirmasi langsung: ZTE F663NV3.1 melaporkan indeks
7/10/11/67/68, Huawei EG8141A5 melaporkan 1/2) — `mac_address` adalah
satu-satunya kunci identitas yang aman dipakai, sesuai unique constraint
tabel ini.

### `GET /cpe-devices/{cpe_device}/connected-hosts`

List (paginated, `?per_page=`) semua host — aktif maupun histori,
diurutkan `last_seen_at` terbaru dulu. Query param `?active_only=true`
membatasi ke yang `is_active` saja. Response `CpeConnectedHostResource`:
`mac_address`, `hostname` (nullable), `ip_address` (nullable),
`is_active`, `first_seen_at`, `last_seen_at`.

## Dashboard Monitoring API (v0.8.4)

**Cikal bakal integrasi bot WhatsApp** (belum dibangun sendiri di sprint
ini) — read-only, membungkus data yang sama persis dengan yang sudah
ditampilkan di halaman `/monitoring`
(`App\Livewire\Network\DeviceMonitoringList`/`DeviceTrafficGraph`) dan
modal "Riwayat" RX Power CPE
(`App\Livewire\Network\CpeSignalHistoryGraph`) — tidak ada logic query
baru, murni endpoint baru di atas service yang sudah ada. Semua 3
endpoint butuh permission `monitoring.view` (kecuali signal history CPE,
lihat di bawah) dan dibatasi `throttle:60,1`, sama seperti rate limit yang
sudah dipakai di 2 webhook publik.

**Vocabulary `?range=`** dipakai bersama oleh 2 dari 3 endpoint ini (lihat
`App\Enums\CpeSignalHistoryRange::fromApiParam()`): `hourly`, `daily`
(default), `weekly`, `monthly`, `yearly`. Nilai di luar 5 kata ini
menghasilkan `422` dengan `errors.range`.

### `GET /cpe-devices/{cpe_device}/signal-history`

Riwayat RX Power (dBm) satu perangkat CPE — query yang sama persis dengan
yang dipakai modal "Riwayat" (`App\Services\Network\
CpeSignalHistoryQueryService::seriesFor()`), termasuk agregasi SQL-level
untuk range Week/Month/Year (lihat bagian "GenieACS Connected Clients"
dan CLAUDE.md "RX Power History (v0.8.3)" untuk detail agregasinya).
Otorisasi mengikuti `CpeDevicePolicy::view()` yang sudah ada — bukan
`monitoring.view` — device milik reseller sendiri tetap bisa diakses staff
reseller itu, sama seperti endpoint CPE lain.

Query param: `?range=` (opsional, default `daily`).

Response `data` — array flat, satu elemen per titik:

```json
{
  "success": true,
  "message": "Riwayat RX Power perangkat CPE",
  "data": [
    { "timestamp": 1755590400, "rx_power_dbm": -22.5 },
    { "timestamp": 1755591000, "rx_power_dbm": null }
  ],
  "meta": []
}
```

`rx_power_dbm: null` adalah gap nyata (bukan error) — sama seperti yang
sudah didokumentasikan untuk grafik Livewire-nya.

### `GET /monitoring/devices`

Daftar semua device LibreNMS beserta ringkasan status/CPU/Memory/
Temperature/Availability — delegasi penuh ke
`App\Services\Network\DeviceMonitoringSummaryService::buildRow()` (dipakai
juga oleh `DeviceMonitoringList` Livewire, diekstrak sprint ini supaya API
dan UI Livewire berbagi logic yang sama persis, bukan duplikat). Butuh
permission `monitoring.view`.

Setiap metric (`cpu`/`memory`/`temperature`/`availability`) punya 3
kemungkinan state, dihitung independen per metric per device — satu
device/metric gagal tidak pernah menyembunyikan device/metric lain:
- `"ok"` — nilai riil (rata-rata kalau device punya beberapa sensor kelas
  yang sama, mis. OLT ZTE C300 punya 7 sensor processor).
- `"no_sensor"` — device memang tidak punya sensor kelas ini sama sekali
  (bukan error, contoh nyata: OLT HSGQ-E04ID tidak punya OID CPU/suhu).
- `"unavailable"` — pemanggilan API LibreNMS-nya sendiri gagal (network,
  5xx, timeout) — dependency genuinely degraded.

`availability.value` khusus menampilkan durasi 1 hari (LibreNMS selalu
mengembalikan 4 durasi tetap; 1 hari dianggap paling relevan untuk "apakah
ini online sekarang").

```json
{
  "success": true,
  "message": "Daftar device monitoring",
  "data": [
    {
      "device_id": 2,
      "hostname": "c300.kaliwungu.bajastu.id",
      "name": "c300.kaliwungu.bajastu.id",
      "status": true,
      "uptime": 100000,
      "cpu": { "state": "ok", "value": 3.0 },
      "memory": { "state": "ok", "value": 41.2 },
      "temperature": { "state": "no_sensor", "value": null },
      "availability": { "state": "ok", "value": 99.98 }
    }
  ],
  "meta": []
}
```

### `GET /monitoring/devices/{device}/traffic`

Riwayat traffic (bukan gambar grafik, time-series mentah) satu interface
device — delegasi langsung ke
`App\Services\Network\LibreNmsService::getTrafficHistory()` (`rrdtool
xport`, sudah melakukan konsolidasi/downsampling sendiri lewat RRA RRDtool
untuk range yang lebih lebar — tidak ada agregasi tambahan di sisi PHP).
Butuh permission `monitoring.view`.

Query param: `interface` (**wajib**, nama interface persis seperti di
LibreNMS, mis. `ether1`), `?range=` (opsional, default `daily`).

```json
{
  "success": true,
  "message": "Riwayat traffic device monitoring",
  "data": [
    { "timestamp": 1755590400, "in_bytes_per_second": 125000.5, "out_bytes_per_second": 84000.2 }
  ],
  "meta": []
}
```

Nilai `null` pada `in_bytes_per_second`/`out_bytes_per_second` berarti
tidak ada sample RRD pada titik waktu itu — bukan nol.

### `GET /monitoring/containers`

Snapshot CPU%/Memory/Network/Disk **terakhir** (bukan live) untuk setiap
container Docker — dibaca dari `container_stats_history`, diisi setiap 5
menit oleh `App\Console\Commands\SyncContainerStats` lewat
`docker-stats-proxy` (baca-saja secara struktural, lihat CLAUDE.md
"Container Stats via docker-socket-proxy (v0.8.4 Bagian C)"). Butuh
permission `monitoring.view`. Tidak ada query param — selalu snapshot poll
paling baru.

```json
{
  "success": true,
  "message": "Snapshot stats container terakhir",
  "data": [
    {
      "container_name": "genieacs-cwmp",
      "cpu_percent": 6.15,
      "memory_usage_mb": 9.91,
      "memory_limit_mb": 19762.49,
      "network_rx_bytes": 7684,
      "network_tx_bytes": 309168,
      "disk_usage_mb": 0.03,
      "recorded_at": "2026-08-22T09:05:00.000000Z"
    }
  ],
  "meta": []
}
```

`disk_usage_mb` adalah `SizeRw` (writable layer milik container itu
sendiri), bukan `SizeRootFs` (yang sebagian besar mencerminkan image layer
bersama, bukan pertumbuhan sesungguhnya container tersebut). Kalau belum
ada satu pun siklus sinkronisasi yang selesai, `data` adalah array kosong.

### `GET /monitoring/devices/{device}/history`

Riwayat CPU/Memory/Suhu **per sensor** (device dengan beberapa sensor —
mis. OLT ZTE C300 punya 7 sensor processor — tidak dirata-rata, satu
elemen array per sensor). Delegasi ke
`App\Services\Network\LibreNmsService::getMetricHistory()`, mekanisme yang
sama dengan endpoint traffic (`rrdtool xport`, tidak ada agregasi PHP
tambahan). Butuh permission `monitoring.view`.

Query param: `metric` (**wajib**, salah satu dari `cpu`/`memory`/
`temperature`), `?range=` (opsional, default `daily`).

```json
{
  "success": true,
  "message": "Riwayat metrik device monitoring",
  "data": [
    {
      "sensor_id": 49,
      "label": "PRWH Processor",
      "points": [
        { "timestamp": 1755590400, "value": 6.15 },
        { "timestamp": 1755590700, "value": 5.8 }
      ]
    }
  ],
  "meta": []
}
```

### `GET /monitoring/devices/{device}/syslog`

Riwayat syslog untuk satu device — cikal bakal integrasi WhatsApp bot
(notifikasi PPP down, error kritis, dll), belum dibangun sekarang, tapi
datanya sudah bisa diakses. Data ini datang dari NAS MikroTik yang
dikonfigurasi kirim syslog (`/system logging action add type=remote`) ke
`rsyslog-receiver` (sidecar baru, v0.8.4), yang meneruskannya ke LibreNMS
via `POST /api/v0/syslogsink` — lihat CLAUDE.md bagian syslog untuk detail
arsitektur. Delegasi ke `App\Services\Network\LibreNmsService::getSyslog()`,
yang membaca LibreNMS's sendiri `GET /logs/syslog/{device_id}`, bukan query
langsung ke `librenms_db`. Butuh permission `monitoring.view`.

Query param: `limit` (opsional, default 50, maksimum 500), `level`
(opsional, severity numerik syslog standar 0-7, mis. `4`=warning,
`6`=info, `7`=debug — difilter di sisi BOSS App karena LibreNMS's sendiri
tidak punya filter level di endpoint ini). **Tidak ada filter `topic`** —
topik RouterOS (`ppp`/`pppoe`/`system`/dst) tidak pernah disimpan di skema
tabel `syslog` LibreNMS begitu data masuk, jadi tidak ada yang bisa
difilter berdasarkan itu setelah data ter-ingest.

```json
{
  "success": true,
  "message": "Riwayat syslog device monitoring",
  "data": [
    {
      "timestamp": "2026-08-25 05:56:53",
      "host": "ro-hotspot.bajastu.id",
      "program": "USER",
      "level": 4,
      "msg": "081285205789 authentication failed"
    }
  ],
  "meta": []
}
```

### `PATCH /monitoring/devices/{device}`

Edit device — whitelist field yang sama dengan form Edit di UI:
`display_name` (opsional, nama tampilan), `community` (SNMP read-only
community), `port`, `snmp_version` (`v1`/`v2c` saja). Butuh permission
`monitoring.manage`. `hostname`/`ip` sengaja tidak bisa diubah lewat
endpoint ini (mengubah identitas jaringan device adalah operasi jauh
lebih berisiko dan tidak diminta di sprint ini).

```json
{ "success": true, "message": "Device berhasil diperbarui", "data": null, "meta": [] }
```

### `DELETE /monitoring/devices/{device}`

Hapus device dari LibreNMS — **destruktif**, riwayat RRD dan data
port/sensor device tersebut ikut terhapus, tidak bisa dikembalikan. Butuh
permission `monitoring.manage`.

```json
{ "success": true, "message": "Device berhasil dihapus dari LibreNMS", "data": null, "meta": [] }
```
