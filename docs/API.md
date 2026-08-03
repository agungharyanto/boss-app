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
(`customer_service`, `super_admin`) untuk tulis.

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
(`customer_service`, `super_admin`) untuk tulis. Nested di bawah customer —
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

Permission: `register-customer` (`super_admin`, `sales_internal`, `teknisi`,
`sales_freelance`). Business logic ada di `App\Services\RegistrationService`,
dipakai bareng oleh endpoint ini dan Livewire `RegisterCustomer`.

### `POST /registrations`

Body: `name`, `address`, `phone_number` (wajib), `nik`, `latitude`,
`longitude`, `package` (opsional), `referred_by_agent_id` (opsional, harus
`id` agent milik tenant yang sama).

Aturan atribusi agent: kalau user yang login sudah terhubung ke sebuah
`Agent` (`agents.user_id`), registrasi **selalu** diatribusikan ke agent itu
— `referred_by_agent_id` di body diabaikan. Kalau user tidak terhubung ke
agent manapun (mis. `super_admin` mendaftarkan langsung), `referred_by_agent_id`
dipakai kalau dikirim, atau `registration_channel` jadi `admin` tanpa
referral kalau tidak.

Setiap registrasi dengan agent otomatis membuat satu baris `commission_ledger`
berstatus `pending` (`amount` masih null — diisi di sprint v0.9.0 Commission).
Response `201` berisi `CustomerResource` seperti `POST /customers`.

### `GET /referrals`

Daftar customer yang direferensikan oleh agent milik user yang login, plus
status commission masing-masing. `404` kalau user yang login tidak terhubung
ke `Agent` manapun (`agents.user_id`). Tidak ada konsep kode referral yang
di-generate/divalidasi di codebase ini — atribusi agent murni lewat link
`agents.user_id`, bukan kode.

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
`resellers.view` untuk read — hanya `super_admin` yang punya keduanya).
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
`reseller_id` diabaikan untuk mereka, sama seperti pola atribusi agent di
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
