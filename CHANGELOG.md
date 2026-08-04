# Changelog

Format bebas mengikuti sprint di `docs/ROADMAP.md`. Setiap versi dicatat saat
tag dibuat (RULE BOSS-013).

## v0.6.2 — VPN Server Node #1 (OpenVPN) & Hub-and-Spoke Routing ke FreeRADIUS

- Container Docker baru `openvpn` — dibangun sendiri dari `alpine:3.20` +
  paket `openvpn` (2.6.20) + `easy-rsa` (3.1.7) via apk, bukan image
  komunitas (`kylemanna/openvpn` terakhir update Des 2020). Satu-satunya
  service (selain `boss-nginx` 80/443) dengan host port publik: UDP 1194
  (BOSS-010, exception disengaja — NAS Mikrotik asli connect dari luar).
- `boss-network` sekarang punya IPAM subnet tetap (`172.28.0.0/24`) dan
  `freeradius` di-pin ke IP statis `172.28.0.10` — locked decision "FreeRADIUS
  selalu diakses di SATU IP internal tetap dari sisi Mikrotik" butuh alamat
  yang tidak pernah drift saat container di-recreate.
- Tabel baru `vpn_servers` (platform-level, bukan tenant/reseller-scoped —
  pola sama `payment_gateway_settings`) dan `vpn_accounts` (`cert_serial`,
  `revoked_at` untuk CRL, unique `internal_ip`). Tabel baru `vpn_ip_pool`
  (tidak diminta eksplisit di spec awal, ditambahkan karena alokasi IP
  race-condition-safe butuh pool of rows to lock — pola sama `odp_ports`
  v0.5.0, bukan tabel IP pool di spec, keputusan implementasi) —
  `VpnServer::provisionIpPool()` (via `App\Support\CidrRange`, unit-tested)
  meng-generate satu baris per host address usable di `subnet_cidr`.
- `App\Services\Network\VpnProvisioningService::provision()`/`revoke()` —
  alokasi IP di dalam `lockForUpdate()` transaction (cepat, commit dulu),
  BARU jalankan `easyrsa build-client-full` (lambat, di luar transaction —
  gagal di sini roll back alokasi IP, bukan menyisakan row "active" palsu).
  `revoke()` = `easyrsa revoke` + `gen-crl`, TANPA restart/reload daemon
  openvpn — dikonfirmasi dari dokumentasi resmi OpenVPN: CRL dibaca ulang
  otomatis di setiap koneksi baru/renegosiasi TLS.
- Arsitektur provisioning: `boss-app` dan `openvpn` berbagi volume Docker
  bernama (`vpn_pki`, `vpn_ccd`) — Laravel menjalankan `easyrsa` langsung
  (Process facade) terhadap PKI yang sama yang di-bootstrap container
  `openvpn` saat first boot, BUKAN docker exec dari host (tidak ada Docker
  socket yang di-mount ke `boss-app`).
- Isolasi jaringan hub-and-spoke: `client-config-dir` untuk `ifconfig-push`
  IP statis per-NAS, SATU `push route` global ke IP FreeRADIUS saja, iptables
  `FORWARD` default-DROP dengan satu `ACCEPT` eksplisit ke IP FreeRADIUS,
  MASQUERADE trafik keluar dari subnet VPN — NAS pelanggan tidak bisa reach
  `boss-postgresql`/`boss-redis`/container lain di `boss-network`.
- REST API `POST /nas/{nas}/vpn-account` (provision) dan
  `POST /vpn-accounts/{vpn_account}/revoke` — otorisasi diturunkan dari
  `NasPolicy::manage()` terhadap NAS pemilik (tidak ada `VpnAccountPolicy`
  terpisah, `vpn_accounts` tidak punya `reseller_id`/`tenant_id` sendiri,
  pola sama `odp_ports`/`work_order_photos`).

Bug nyata ditemukan & diperbaiki saat verifikasi end-to-end (bukan cuma
dites dengan mock — dibuktikan lewat `easyrsa` sungguhan dari container
`boss-app` terhadap PKI yang di-bootstrap container `openvpn`, provisioning
sungguhan lewat HTTP-equivalent service call, dan revoke sungguhan):
- `easyrsa init-pki` melakukan hard-reset (`rm -rf`) pada `--pki-dir` itu
  sendiri — gagal dengan `Resource busy` kalau `--pki-dir` PERSIS di root
  mount point volume Docker. Diperbaiki dengan memindahkan PKI ke
  subdirectory (`pki-data/pki`), bukan langsung di root mount.
- Volume shared butuh permission permisif (`chmod -R 0777` di entrypoint
  `openvpn`) karena `boss-app` (php-fpm worker jalan sebagai `www-data`,
  UID beda dari proses root di container `openvpn`) perlu menulis
  cert/key baru ke direktori yang sama.
- `Process::fake()` di Laravel mencocokkan pattern terhadap
  `Symfony\Process::getCommandline()` yang MENGUTIP tiap argumen
  (`'easyrsa' '--pki-dir=...'`) — pattern wildcard harus diawali `*`
  (`'*easyrsa*build-client-full*'`), bukan `'easyrsa*...'` polos, atau
  fake tidak pernah ter-trigger dan test diam-diam menjalankan proses asli.
- `docker/php/Dockerfile` menambah `openssl`/`easy-rsa` ke layer `apk add`
  yang SAMA dengan `linux-headers` (v0.6.1) — meng-invalidate cache layer
  `docker-php-ext-install sockets` sesudahnya, memicu kompilasi ulang dari
  nol (~7 menit) walau tidak ada perubahan pada extension itu sendiri.
  Bukan bug fungsional, dicatat sebagai pelajaran Dockerfile-layering untuk
  sprint berikutnya (taruh paket baru di layer terpisah kalau ingin cache
  tetap valid).

Out-of-scope v0.6.2, di-declare eksplisit sebagai backlog: WireGuard/L2TP
(v0.6.3), script generator Mikrotik siap-paste (v0.6.3), multi-node pool +
health-check otomatis + failover (v0.6.4, `vpn_servers` baru 1 baris manual
lewat tinker — belum ada `VpnServerController`/REST API karena CRUD-nya baru
jadi kebutuhan nyata saat multi-node), dynamic virtual server + CoA
(v0.6.5), force-disconnect sesi VPN yang sudah terkoneksi saat revoke (perlu
OpenVPN management interface, belum dibangun), Docker healthcheck untuk
`openvpn` (monitoring VPN node sungguhan adalah pekerjaan v0.6.4).

Detail lengkap ada di CLAUDE.md bagian "VPN Server Node #1 (v0.6.2)".

## v0.6.1 — FreeRADIUS Core & NAS Management

- Container Docker baru `freeradius-db` (Postgres 16, terpisah dari
  `boss-postgresql` — BOSS-009: `radius_db` logically separated dari
  `boss_db`, no cross-database join) dan `freeradius`
  (`freeradius/freeradius-server:3.2.10-alpine`, build custom di
  `docker/freeradius/`). Tidak ada host port dipublikasikan (BOSS-010) —
  belum ada NAS/VPN yang perlu menjangkaunya dari luar sampai v0.6.2.
- Schema PostgreSQL standar FreeRADIUS (`radcheck`, `radreply`,
  `radgroupcheck`, `radgroupreply`, `radusergroup`, `radacct`,
  `radpostauth`, `nas`, `nasreload`) diambil dari upstream
  `FreeRADIUS/freeradius-server` v3.2.x dan di-load otomatis lewat
  `docker-entrypoint-initdb.d` saat `freeradius-db` pertama kali start.
- `rlm_sql` diarahkan ke `radius_db` lewat `docker/freeradius/mods-available/sql`
  (dialect `postgresql`, driver `rlm_sql_postgresql`, connection string dari
  `$ENV{RADIUS_DB_*}`) — diverifikasi end-to-end: `radcheck` row manual ->
  `radclient auth` -> `Access-Accept` sungguhan, bukan cuma "container tidak
  crash".
- Tabel baru `nas` (di `boss_db`, BUKAN tabel `nas` bawaan FreeRADIUS di
  `radius_db` — nama sama, database beda) — inventaris router Mikrotik:
  `reseller_id` nullable (pola sama dengan `whatsapp_sessions`/
  `work_orders`), `mikrotik_ip` nullable sengaja (baru terisi lewat VPN
  provisioning v0.6.2), `api_password`/`radius_secret` encrypted,
  `auth_port`/`acct_port` nullable sampai port allocator v0.6.5,
  `coa_port` default 3799.
- `App\Services\Network\NasService` (CRUD + `testConnection()`) dan
  `App\Services\Network\Contracts\RouterOsGateway` (interface,
  implementasi nyata `RouterOsApiGateway` pakai
  `evilfreelancer/routeros-api-php` — butuh `ext-sockets`, baru
  ditambahkan ke `docker/php/Dockerfile`) — dibuat sebagai boundary
  eksplisit supaya test tidak perlu router sungguhan (bind fake
  implementasi lewat container, tidak ada `Http::fake()` karena
  protokolnya raw socket, bukan HTTP).
- REST API `GET/POST /nas`, `GET/PUT/DELETE /nas/{nas}`,
  `POST /nas/{nas}/test-connection` — reseller-scoped lewat
  `BelongsToResellerScope` + `NasPolicy` (pola identik
  Odp/Technician/WorkOrder Policy v0.5.0). Response tidak pernah
  menyertakan nilai asli `api_password`/`radius_secret`.
- Permission `nas.view`/`nas.manage` (super_admin-only, pola sama dengan
  `seedInstallationPermissions()`).
- `docs/ROADMAP.md` diperbarui: `v0.6.0` (FreeRADIUS, satu versi) dipecah
  jadi 5 sub-versi `v0.6.1`-`v0.6.5` untuk mereplikasi pola VPN+RADIUS
  MixRadius V3.2; `v0.6.1` (Mobile Self-Service Portal) yang lama digeser
  ke `v0.11.0`.

**Gap infrastruktur yang ditemukan & diperbaiki saat membangun** (sama
kelas dengan gap `.env`/build-image di sprint-sprint sebelumnya — bukan
scope baru, murni supaya container ini benar-benar bisa dipakai):
- `ext-sockets` gagal dikompilasi di image `php:8.4-fpm-alpine` karena
  Alpine tidak menyertakan kernel header lengkap secara default
  (`linux/sock_diag.h` hilang) — diperbaiki dengan menambah paket
  `linux-headers` ke `docker/php/Dockerfile` sebelum
  `docker-php-ext-install sockets`.
- `rlm_sql_postgresql.so` di image `freeradius/freeradius-server:*-alpine`
  gagal instantiate (`Error loading shared library libpq.so.5`) karena
  base image Alpine tidak menyertakan runtime client Postgres —
  diperbaiki dengan `apk add libpq` di `docker/freeradius/Dockerfile`.
- Sintaks substitusi environment variable FreeRADIUS yang benar di
  config statis (`mods-available/sql`) adalah `$ENV{VAR}` (gaya Perl),
  **bukan** `${env:VAR}` — percobaan pertama gagal dengan error
  `Reference "${env}" not found` karena `${...}` di FreeRADIUS adalah
  sintaks "rujuk nilai config lain", bukan environment-variable
  interpolation.

Detail lengkap ada di CLAUDE.md bagian "FreeRADIUS Core & NAS Management
(v0.6.1)".

## v0.5.0 — Installation (Work Order Teknisi)

- Tabel baru `odps` — inventory ODP (`code` unik per tenant, `latitude`/
  `longitude`, `total_ports`), `reseller_id` nullable (null = milik ISP A
  langsung), index `(latitude, longitude)` untuk query lokasi.
- Tabel baru `odp_ports` — satu baris per port fisik ODP (`port_number`,
  `status`: `available`/`reserved`/`used`/`damaged`, `subscription_id`
  nullable), unique `(odp_id, port_number)`. `Odp::provisionPorts()`
  auto-generate port 1..`total_ports` saat ODP dibuat lewat API (bukan
  model event — sengaja, supaya tidak collision dengan factory test).
- Tabel baru `technicians` — data teknisi (`user_id`, `name`, `phone`,
  `status`), `reseller_id` nullable sama pola dengan modul lain.
- Tabel baru `work_orders` — `reseller_id` didenormalisasi dari
  subscription, `status` state machine 8 nilai (`pending_odp_check` ->
  `pending_verification`/`odp_unavailable` -> `ready` -> `assigned` ->
  `in_progress` -> `completed`, `cancelled` dari status non-terminal
  manapun), `equipment_ready` placeholder manual (modul stok riil belum
  ada).
- Tabel baru `work_order_devices` (device_type ont/router/ap, mac_address,
  serial_number) dan `work_order_photos` (4 jenis wajib: odp/ont_device/
  signal_strength/house_front, unique 1 foto per jenis per WO).
- `App\Services\Installation\OdpLocatorService::findNearestAvailable()` —
  Haversine formula raw SQL (tanpa PostGIS), diverifikasi jalan identik di
  SQLite (test) dan Postgres, scoped ke reseller yang sama dengan customer
  (atau direct), hanya port `available`.
- `App\Services\Installation\WorkOrderService` — `createFromSubscription()`
  (cari & reserve port terdekat, atau `odp_unavailable` kalau tidak ada),
  `verify()`, `assignTechnician()`, `start()`, `complete()` (validasi
  legalitas transisi LEBIH DULU, baru validasi 4 foto + minimal 1 device
  lengkap; port -> `used`), `cancel()` (port kembali `available`).
  `App\Services\Installation\WorkOrderPhotoService::store()` — satu foto
  per jenis, replace file lama kalau upload ulang.
- REST API lengkap: `GET/POST /odps`, `GET/POST /technicians`,
  `GET/POST /work-orders` + `POST /subscriptions/{id}/work-order` +
  `verify`/`assign`/`start`/`photos`/`devices`/`complete`/`cancel`, semua
  scoped `reseller.context` + `OdpPolicy`/`TechnicianPolicy`/
  `WorkOrderPolicy` (permission `odps.*`/`technicians.*`/`work_orders.*`
  super_admin-only, reseller owner/staff lewat `reseller_users`
  membership).
### Deferred
- Modul stok/inventory barang riil (equipment_ready tetap manual)
- Notifikasi WhatsApp otomatis untuk event work order
- Komponen UI scan kamera html5-qrcode (endpoint API sudah siap)

## v0.4.0 — WhatsApp Gateway (Baileys, multi-sesi per reseller)

- Tabel baru `whatsapp_sessions` — satu sesi WhatsApp per reseller (`reseller_id`
  nullable, null = sesi "direct" ISP), `status` (`qr_pending`/`connected`/
  `disconnected`/`logged_out`), `qr_code_data`, `last_connected_at`/
  `last_disconnected_at`. Partial unique index terpisah untuk
  `reseller_id IS NOT NULL` (satu sesi per reseller) dan `reseller_id IS NULL`
  (maksimal satu sesi direct per tenant).
- Tabel baru `whatsapp_message_templates` — template per `event_type` (4 jenis:
  `invoice_due_reminder`, `payment_received`, `customer_registered`,
  `customer_suspended_reminder`), `reseller_id` nullable (null = default
  ISP-level), `is_active`. Partial unique index sama polanya dengan sessions.
- Tabel baru `whatsapp_message_logs` — audit trail tiap pesan (`customer_id`,
  `invoice_id` nullable, `phone_number`, `event_type`, `rendered_content`,
  `status`, `failed_reason`, `attempts`), index dedup `(invoice_id, event_type,
  created_at)` dan `(customer_id, event_type, created_at)` untuk guard
  reminder harian.
- Tabel baru `whatsapp_gateway_settings` — singleton platform-level (rate
  limit delay/batch size/jeda batch/jadwal harian), posisi sama seperti
  `payment_gateway_settings`.
- `App\Support\WhatsappHmac` — HMAC-SHA256 signing/verification internal
  Laravel<->Node, timestamp + toleransi 5 menit (beda dari verifikasi
  static-token Xendit).
- `App\Services\Whatsapp\WhatsappGatewayService::buildAndQueue()` — resolve
  template (override reseller > default ISP), render variabel, simpan log,
  dispatch `SendWhatsappMessageJob` ke queue `whatsapp-{session_key}`.
  `App\Services\Whatsapp\WhatsappTemplateService::resolve()`/`render()`.
  `App\Services\Whatsapp\WhatsappSessionService` — verifikasi webhook +
  reconciliation hourly dari Node gateway.
- `App\Jobs\SendWhatsappMessageJob` — rate limit delay (random 5-10 detik),
  retry max 3x dengan backoff 30s/2menit/5menit, POST HMAC-signed ke Node
  service.
- 3 scheduled command baru: `whatsapp:send-due-reminders` (H-5 & H-0, dedup
  harian, self-gate terhadap `daily_schedule_times`), `whatsapp:send-suspended-reminders`
  (harian selama status masih suspend, berhenti otomatis), `whatsapp:check-session-health`
  (hourly, reconcile status sesi dari Node gateway).
- Hook terintegrasi ke `InvoiceService::markPaid()` (event `payment_received`,
  signature TIDAK diubah) dan `RegistrationService::register()` (event
  `customer_registered`, di luar `DB::transaction()`).
- Endpoint publik `POST /api/v1/whatsapp/webhook/session-status` (HMAC
  verified, always 200) + REST API sessions/templates/message-logs/settings
  (scoped via `BelongsToResellerScope` + `WhatsappSessionPolicy`/
  `WhatsappMessageTemplatePolicy`/`WhatsappMessageLogPolicy`/
  `WhatsappGatewaySettingsPolicy`).
- Node.js service baru `whatsapp-gateway/` (container `whatsapp-gateway`,
  internal-only, tanpa host port) — multi-session Baileys
  (`@whiskeysockets/baileys`), auth state per sesi di volume
  `whatsapp-gateway/auth_state/{session_key}/`, endpoint
  `/sessions/{key}/send|qr|health` + `/sessions` (list), webhook balik ke
  Laravel di setiap `connection.update`.
- Container baru `boss-whatsapp-worker` — `queue:work` dengan daftar queue
  dinamis dari `whatsapp:queue-names` (artisan command baru), restart tiap 5
  menit (pola sama dengan `boss-scheduler`) supaya sesi reseller baru
  otomatis ikut terdengar.
- Livewire `Whatsapp\WhatsappGatewayIndex` (`/whatsapp-gateway`, cluster
  sidebar baru "Komunikasi") — reseller: tab Konfigurasi (QR)/Template/
  Antrian scoped ke reseller sendiri; ISP admin: tab Overview semua sesi +
  Template default + Antrian gabungan + Rate Limit settings.
- Permission baru: `whatsapp_gateway.view`/`.manage`,
  `whatsapp_gateway_settings.view`/`.manage` (super_admin-only — reseller
  owner/staff diotorisasi lewat `reseller_users` membership, bukan Spatie
  permission, sama pola dengan reseller/tax engine).
- `payment_gateway:import-env`-style one-time command:
  `payment-gateway:import-env` tidak berubah; tambahan `WHATSAPP_GATEWAY_URL`/
  `WHATSAPP_GATEWAY_HMAC_SECRET` di `.env.example` (root + `app/`) dan
  `config('services.whatsapp_gateway.*')`.
### Deferred
- Two-way messaging + integrasi Chatwoot
- Overdue reminder berulang (sengaja tidak ada sama sekali)
- Notifikasi work order teknisi / outage alert
- Rate limit setting per-reseller (sprint ini global saja)
- Auto-refund, retry payment flow, partial payment/cicilan, proration
  subscription (tetap deferred dari v0.3.4/v0.3.5)

## v0.3.5 — Payment Gateway (Xendit)

- Tabel baru `payments` — satu baris per attempt/instance pembayaran
  (`tenant_id`, `invoice_id`, `xendit_reference_id`, `channel_type`, `amount`,
  `status`, `paid_at`, `raw_response` jsonb), tenant-scoped via
  `BelongsToTenant`.
- Tabel baru `payment_webhook_logs` — audit trail platform-wide (sengaja
  TANPA `tenant_id`/FK), `xendit_event_id` UNIQUE sebagai backstop
  idempotency, `payload` jsonb, `signature_valid`, `processed_at`,
  `processing_result`.
- Enum baru: `App\Enums\PaymentStatus` (`pending`, `paid`, `failed`,
  `expired`), `App\Enums\WebhookProcessingResult` (`applied`, `duplicate`,
  `rejected_signature`, `rejected_amount_mismatch`, dan satu tambahan
  `rejected_invoice_not_found` di luar daftar awal — kegagalan yang genuinely
  berbeda dari amount-mismatch, kolomnya string biasa jadi aman ditambah).
  (`App\Enums\PaymentChannelType`, 3 case tetap, sempat ada di Fase A-G —
  **dihapus lagi di Fase H**, lihat bawah.)
- `App\Services\Payment\XenditGatewayService` — wrapper HTTP murni ke
  `https://api.xendit.co` (Basic Auth pakai secret key), tidak tahu apa-apa
  soal `Invoice`. Constructor menolak beroperasi (`RuntimeException`) kalau
  `XENDIT_IS_PRODUCTION=true` tapi `APP_ENV` bukan `production` — guard
  eksplisit supaya sandbox key tidak bisa "ke-upgrade" diam-diam.
- `App\Services\Payment\PaymentService` — `createPaymentFor()` (invoice +
  channel → panggil gateway sesuai channel → simpan `Payment`) dan
  `handleWebhook()` dengan urutan tetap: (1) verifikasi `x-callback-token`
  lebih dulu, tolak sebelum payload disentuh sama sekali kalau invalid;
  (2) dalam DB transaction, cek idempotency by `xendit_event_id`
  (`lockForUpdate`); (3) exact match `payload.amount` vs
  `invoice.grand_total` (bukan `>=`); (4) baru panggil
  `InvoiceService::markPaid()`. `invoice_number` (BUKAN `id` numerik) dipakai
  sebagai Xendit `external_id` di semua channel.
- Webhook endpoint publik `POST /api/v1/webhooks/xendit` (di luar
  `auth:sanctum`, throttle 60/menit) — SELALU balas HTTP 200 apa pun hasil
  internalnya (applied/duplicate/rejected/error) supaya Xendit berhenti
  retry; error internal ditangkap dan dicatat, tidak pernah bocor ke
  response.
- REST API baru: `GET/POST /api/v1/invoices/{invoice}/payments` (list +
  create payment attempt), permission `invoice.manage`/`invoice.view`
  existing dari v0.3.4, di dalam grup `reseller.context`.
- Livewire `Billing\ReconciliationReport` (`/payment-reconciliation`) —
  laporan read-only: invoice berstatus `paid` dicocokkan dengan `Payment`
  ber-status `paid` miliknya (baris tanpa match ditandai "ANOMALY"), plus
  daftar `PaymentWebhookLog` yang hasilnya bukan `applied`. **Tidak ada
  tombol perbaikan otomatis** — sengaja murni laporan/audit.
- **Keputusan arsitektur yang dikonfirmasi eksplisit oleh Agung**: endpoint
  manual `PATCH /api/v1/invoices/{invoice}/paid` (dari v0.3.4) **dipertahankan
  apa adanya**, meski tidak melalui verifikasi pembayaran sama sekali.
  Konsekuensi: `InvoiceService::markPaid()` sekarang punya dua jalur
  pemanggil yang sah — endpoint admin manual (tanpa verifikasi) dan webhook
  Xendit (dengan verifikasi penuh: signature + idempotency + exact amount
  match). Jangan "bersihkan" ini secara sepihak di sprint berikutnya; lihat
  `docs/ROADMAP.md` dan CLAUDE.md untuk detail.
- Config: `XENDIT_IS_PRODUCTION` tetap di root `.env`/`config('services.xendit.*')`
  (guard sandbox, lihat di bawah) — `XENDIT_SECRET_KEY`/`XENDIT_CALLBACK_TOKEN`
  di root `.env` HANYA dipakai untuk migrasi awal (lihat Fase H), bukan lagi
  sumber runtime. Tidak pernah hardcoded atau ter-commit.

### Fase H (amendment, sebelum commit pertama — bukan sprint baru)

Penambahan scope dikonfirmasi Agung sebelum branch ini di-commit: UI
Pengaturan Payment Gateway ala referensi MikRadius. Mengubah dua kontrak inti
dari Fase A-G di atas (bukan cuma penambahan):

- Tabel baru `payment_gateway_channels` — katalog channel Xendit yang bisa
  diatur admin (`code` unique, `label`, `category` enum
  `App\Enums\PaymentGatewayChannelCategory`, `enabled`). Diseed lewat
  `PaymentGatewayChannelSeeder` (18 channel: 9 bank VA, credit card, 2 retail
  outlet, 4 e-wallet, QRIS, Xendit Invoice — semua `enabled=false` sampai
  admin mengaktifkan lewat UI).
- Tabel baru `payment_gateway_settings` — singleton platform-level (id=1,
  dijaga oleh service, bukan constraint DB) menyimpan `xendit_secret_key`/
  `xendit_webhook_token` (cast `encrypted`), `is_configured`, `updated_by`.
- **`payments.channel_type` bukan lagi enum tetap** — `App\Enums\PaymentChannelType`
  dihapus. Sekarang varchar yang mereferensi `payment_gateway_channels.code`
  (tanpa hard FK, divalidasi di `PaymentService`). Migration
  `remap_legacy_payment_channel_types` membackfill baris lama Fase A-G
  (`virtual_account`→`BRI_VA`, `qris`→`QRIS`, `invoice`→`XENDIT_INVOICE`) agar
  tetap valid di bawah vocabulary baru — kolomnya sendiri sudah varchar sejak
  awal, jadi ini murni migrasi data, bukan alter tipe kolom.
- `App\Services\Payment\PaymentGatewaySettingsService` — satu-satunya
  pembaca/penulis `payment_gateway_settings`/`payment_gateway_channels` yang
  sah: `getSecretKey()`/`getWebhookToken()` (di-cache), `update()` (transaction,
  blank submit tidak menghapus value tersimpan, invalidate cache setelah
  commit), `isChannelEnabled()`/`enabledChannels()`.
- `XenditGatewayService`/`PaymentService::verifySignature()` **direfactor**
  untuk membaca secret/token dari service di atas, bukan lagi
  `config('services.xendit.secret_key'/'callback_token')`. `PaymentService::createPaymentFor()`
  sekarang menolak channel yang ada di katalog tapi `enabled=false`, dan
  channel kategori `ewallet`/`retail_outlet`/`credit_card` (ada di katalog
  untuk checklist UI, TAPI belum ada integrasi API Xendit-nya — hanya
  `bank_transfer_va`/`qris`/`invoice` yang benar-benar bisa dipakai sprint
  ini).
- Livewire `Settings\PaymentGatewaySettings` (`/settings/payment-gateway`,
  permission `payment_gateway_settings.manage`/`.view`, super_admin-only —
  lebih ketat dari `invoices.*`) — field API Secret/Webhook Token masked,
  tidak pernah render nilai asli (placeholder "tersimpan, diubah: {tanggal}"),
  submit kosong tidak mengubah value tersimpan, checklist channel per
  kategori, validasi minimal 1 channel aktif.
- Command manual `php artisan payment-gateway:import-env` — sekali jalan,
  mengimpor `XENDIT_SECRET_KEY`/`XENDIT_CALLBACK_TOKEN` lama dari `.env` ke
  `payment_gateway_settings`. Tidak dijalankan otomatis dari migration/seeder.
- Tests tambahan: `PaymentGatewaySettingsServiceTest` (roundtrip encrypted,
  cache invalidation, singleton, sync channel), `PaymentGatewaySettingsLivewireTest`
  (masked placeholder, blank-submit tidak menghapus, validasi minimal 1
  channel, non-admin forbidden), plus 1 test baru di `PaymentServiceSafetyTest`
  (channel disabled ditolak). Total 149/149 test suite passing.

- **Di luar scope, sengaja di-declare sebagai backlog**: notifikasi
  pembayaran via WhatsApp/Baileys (nunggu v0.4.0), auto-refund otomatis,
  retry payment flow (UI maupun service), partial payment/cicilan, proration
  subscription (masih deferred dari v0.3.4), dan integrasi Xendit API nyata
  untuk channel ewallet/retail_outlet/credit_card (baru terdaftar di katalog,
  belum bisa dipakai membuat payment).

## v0.3.4 — Invoicing Core

- Tabel baru `subscriptions` — plan berlangganan per customer.
  `reseller_id`/`reseller_package_pricing_id` **ada sejak migration pertama**
  (dependency wajib dari v0.3.2/v0.3.3, bukan retrofit): `reseller_id`
  didenormalisasi dari `customers.reseller_id` saat dibuat,
  `reseller_package_pricing_id` nullable (null untuk direct-retail — harga
  di-set manual di `monthly_amount`, karena tidak ada katalog `packages` ISP
  di codebase manapun). `billing_cycle_day` (1–31) jadi acuan tanggal jatuh
  tempo bulanan.
- Tabel baru `invoices` + `invoice_line_items`. Nomor invoice **per-reseller**
  (`INV/{kode_reseller}/{tahun}/{bulan}/{sequence 6 digit}`, `INV/DIRECT/...`
  untuk direct-retail — keputusan dikonfirmasi eksplisit sebelum migration
  ditulis) via `InvoiceNumberService` + tabel `invoice_number_sequences`
  (partial unique index Postgres, pola sama dengan `reseller_tax_policies`).
  `resellers.invoice_code` (kolom baru) auto-derive dari slug kalau admin
  tidak set manual.
- **State machine invoice** (`App\Enums\InvoiceStatus`, method
  `canTransitionTo()` mirip `CustomerStatus`): `draft → pending →
  (paid | overdue) → paid`; semua state non-terminal bisa `cancelled`;
  `paid`/`cancelled` terminal. Transisi tidak valid melempar
  `App\Exceptions\InvalidInvoiceStatusTransitionException` (pola sama
  dengan `InvalidStatusTransitionException` milik `Customer` di v0.2.0),
  bukan raw exception generik.
- **`InvoiceService::generateForPeriod()` memenuhi kontrak wajib v0.3.3**:
  memanggil `TaxCalculationService::calculateForAmount()` lalu
  `::writeLedgerEntry()` — `grand_total` invoice selalu dari
  `$breakdown->grandTotal`, tidak pernah dihitung ulang manual.
  Idempoten terhadap subscription+periode yang sama (guard di dalam
  transaction, unique constraint DB sebagai backstop terakhir).
- **Command terjadwal baru** (`boss-scheduler`, daily): `GenerateDueInvoices`
  (generate invoice untuk subscription aktif yang jatuh tempo H-7 — angka 7
  dikonfirmasi eksplisit sebagai keputusan, bukan hardcode diam-diam, via
  flag `--lead-days`) langsung auto-issue ke `pending`; `MarkOverdueInvoices`
  (ubah `pending` yang `due_date`-nya lewat jadi `overdue`).
- **Keputusan arsitektur yang dikonfirmasi eksplisit sebelum migration**
  (sesuai instruksi sprint): nomor invoice per-reseller (bukan sequence
  global), due date dari `billing_cycle_day` + generate H-7, overdue via job
  otomatis harian.
- **Known limitation, sengaja di-defer** (bukan silent gap — lihat
  `docs/ROADMAP.md`): tidak ada proration. Subscription yang mulai/berhenti
  di tengah periode billing tetap ditagih penuh satu periode.
- **Dua bug nyata ditemukan & diperbaiki selama development**:
  1. `InvoiceService::generateForPeriod()` awalnya mengembalikan
     `$invoice->fresh()` — method itu membuat instance BARU dari database,
     me-reset properti `wasRecentlyCreated` Eloquent ke `false` walau baris
     baru saja dibuat. Ini membuat `GenerateDueInvoices` gagal mendeteksi
     invoice yang baru dibuat dan tidak memanggil `markPending()` (invoice
     tertinggal di status `draft`). Fix: pakai `->load()`, bukan `->fresh()`.
  2. **Bug lintas-driver yang meluas** (juga mempengaruhi kode v0.3.3 yang
     sudah ter-tag): kolom ber-cast `'date'` bisa tersimpan dengan sufiks
     waktu di SQLite (dipakai test suite) meski tidak di Postgres (dev/prod),
     membuat `->where('kolom', '<=', $tanggal->toDateString())` gagal cocok
     tepat di tanggal yang sama persis. Fix: `->whereDate(...)` di seluruh
     `TaxCalculationService`, `ResellerTaxPolicyService`,
     `RemittanceSummaryService`, `InvoiceService`, `MarkOverdueInvoices`,
     `TaxLedgerController`, `RemittanceSummaryController`. Detail lengkap +
     alasan supaya tidak terulang di sprint berikutnya: lihat `CLAUDE.md`
     bagian "Cross-database date comparison gotcha".
- **REST API**: `GET/POST /api/v1/subscriptions` +
  `PATCH .../suspend|reactivate|cancel`, `GET /api/v1/invoices` +
  `POST /api/v1/invoices/generate` + `PATCH .../pending|paid|cancel`.
  Permission baru `subscriptions.*`/`invoices.*` — **beda dari pola
  super_admin-only ketat di v0.3.2/v0.3.3**: role `billing` (ada sejak
  v0.1.0, belum pernah dapat permission apa pun) juga diberi akses, karena
  operasional invoice adalah pekerjaan harian staff billing, bukan
  keputusan level admin seperti konfigurasi reseller/kebijakan pajak.
  Reseller owner/staff tetap read-only lewat keanggotaan `reseller_users`
  (`SubscriptionPolicy`/`InvoicePolicy`), sama seperti modul lain.
- **Livewire UI**: `Billing\SubscriptionIndex` (CRUD + tombol Generate
  Invoice Now/suspend/reactivate/cancel), `Billing\InvoiceIndex` (filter
  status + tombol transisi sesuai state machine). Masuk cluster sidebar
  "Billing & Finance" yang sudah ada dari v0.3.3.
- Tests: 125/125 passing (119 existing + 6 file test baru mencakup generate
  invoice normal, generate dengan burden reseller `split`/`reseller_borne`,
  state machine lengkap, validasi no-duplicate per subscription+periode, dan
  command terjadwal — lihat `tests/Feature/Api/SubscriptionInvoiceApiTest.php`,
  `tests/Feature/Billing/`.

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
