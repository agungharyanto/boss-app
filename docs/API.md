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
miliknya sendiri; ISP admin (permission `cpe_devices.view`) melihat semuanya
termasuk yang direct (tanpa reseller). **Tidak ada endpoint create/update/
delete** — satu-satunya cara sebuah baris `cpe_devices` tercipta adalah
otomatis lewat `CpeBindingService::bindFromWorkOrder()`, dipanggil dari hook
di `WorkOrderService::complete()` (best-effort — kegagalan binding tidak
pernah menggagalkan penyelesaian work order itu sendiri). Tidak ada input
manual admin untuk binding device, sesuai keputusan yang dikunci saat
planning sprint ini.

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
