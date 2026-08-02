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
