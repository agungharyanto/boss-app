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
