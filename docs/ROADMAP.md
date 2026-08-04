# BOSS App — Roadmap Sprint (Urutan Dikunci)

| Versi   | Cluster         | Nama                          | Isi Utama                                                                                   | Status  |
|---------|-----------------|-------------------------------|-----------------------------------------------------------------------------------------------|---------|
| v0.1.0  | Operasional     | Foundation                    | Repo, Docker, Laravel, PostgreSQL, Redis, Nginx, login, role, UFW/Fail2ban, backup            | Selesai |
| v0.2.0  | Operasional     | Customer CRM                  | Data pelanggan, kontak keluarga, authorized contact, customer timeline                        | Selesai |
| v0.3.0  | Operasional     | Registration & Referral       | Registrasi multi-channel, agent, referral, komisi pending                                     | Selesai |
| v0.3.1  | UI/UX           | Personalization & Navigation  | Theme custom (primary/text color), language switcher, dashboard widget selector, sidebar cluster-dropdown | Selesai |
| v0.3.2  | Operasional     | Multi-Tenant Reseller         | Tabel resellers (child dari tenant), reseller_id menyebar, guard/role reseller, reseller_package_pricing | Selesai |
| v0.3.3  | Billing & Finance | Regulatory Tax Engine        | tax_components dinamis (nama bebas, persen/nominal, on/off, versi-per-tanggal), reseller_tax_policies, reseller_tax_ledger, komdigi_remittance_summary | Selesai |
| v0.3.4  | Billing & Finance | Invoicing Core               | Subscription plan per customer, generate invoice bulanan, invoice line items, status invoice   | Selesai |
| v0.3.5  | Billing & Finance | Payment Gateway (Xendit)     | Integrasi Xendit (VA/QRIS/invoice), webhook handler + signature verification, idempotency, reconciliation | Selesai |
| v0.4.0  | Komunikasi      | Communication (Baileys)       | WhatsApp gateway multi-sesi per reseller, template pesan, reminder invoice/suspend, reconciliation session | Selesai |
| v0.5.0  | Operasional     | Installation                  | Work order teknisi (ODP locator Haversine, state machine, scan device, 4 foto wajib)          | Selesai |
| v0.6.1  | Network         | FreeRADIUS Core & NAS Management | Container FreeRADIUS terpisah + rlm_sql ke `radius_db` (Postgres terpisah), tabel `nas` (port RADIUS unik per-NAS sejak migration pertama), NasService CRUD + test koneksi Mikrotik API | Selesai |
| v0.6.2  | Network         | VPN Server Node #1 (OpenVPN)  | Hub-and-spoke: VPN node sebagai concentrator/relay, FreeRADIUS diakses di satu IP internal tetap dari sisi Mikrotik                          | Aktif |
| v0.6.3  | Network         | Multi-Protokol VPN & Script Generator | WireGuard, L2TP/IPsec (SSTP di-skip), Script Generator (VPN + RADIUS script siap-paste ke terminal Mikrotik)                    | Backlog |
| v0.6.4  | Network         | VPN Pool & Failover           | Schema `vpn_servers` siap N>1 node + health-check + auto-switch failover (N=1 node aktif sekarang, backend siap tanpa retrofit) | Backlog |
| v0.6.5  | Network         | Dynamic Virtual Server & CoA  | Virtual server FreeRADIUS dinamis per-NAS + port allocator + CoA/disconnect (port 3799) untuk isolir instan                     | Backlog |
| v0.7.0  | Network         | GenieACS                      | Binding ONT, SSID/password, RX power, reboot, provisioning                                    | Backlog |
| v0.8.0  | Network         | LibreNMS & Graph              | Device monitoring, graph jaringan, graph pemakaian per-pelanggan, alert                       | Backlog |
| v0.9.0  | Billing & Finance | Commission                   | Eligibility, approval, payment, clawback (menyempurnakan commission_ledger v0.3.0)             | Backlog |
| v0.10.0 | Network         | Outage Engine                 | ONT down detection, korelasi area, incident, maintenance                                      | Backlog |
| v0.11.0 | Customer App    | Mobile Self-Service Portal    | Auth guard customer terpisah, ganti password (OTP), cek pemakaian, bayar tagihan               | Backlog |

Kita tidak loncat versi dalam satu cluster. Setiap versi selesai penuh
(lihat Definition of Done di RULES.md) sebelum lanjut ke versi berikutnya.
Urutan antar-cluster mengikuti dependency teknis: reseller sebelum
billing/tax (hindari retrofit), tax sebelum invoicing, invoicing sebelum
payment gateway, FreeRADIUS sebelum mobile app & usage graph.

**Amendment saat v0.6.1 dimulai (dikonfirmasi Agung)**: `v0.6.0` FreeRADIUS
(satu versi) dipecah jadi 5 sub-versi `v0.6.1`-`v0.6.5` karena scope-nya
besar — mereplikasi pola VPN+RADIUS dari referensi kompetitor MixRadius V3.2.
Ini membuat slot `v0.6.1` yang sebelumnya dipakai "Mobile Self-Service
Portal" bentrok; Mobile Self-Service Portal **digeser ke `v0.11.0`** (akhir
backlog, setelah cluster Network selesai penuh) — bukan disisipkan di
tengah lagi. Nomor `v0.7.0`-`v0.10.0` (GenieACS/LibreNMS/Commission/Outage)
tidak berubah.

**Keputusan arsitektur terkunci untuk seluruh cluster `v0.6.x`** (jangan
dinegosiasi ulang tanpa konfirmasi eksplisit baru, BOSS-003):
- VPN multi-protokol: OpenVPN, WireGuard, L2TP/IPsec. SSTP di-skip (server
  open-source kurang matang).
- Topologi VPN pool-ready dari awal (schema `vpn_servers` di v0.6.4 sudah
  mengakomodasi banyak node + health-check + failover), tapi implementasi
  aktual cuma 1 node untuk sekarang (server dev = calon server produksi
  in-place, tidak ada VPS terpisah saat ini). Model hub-and-spoke: VPN node
  adalah concentrator/relay, FreeRADIUS selalu diakses di satu IP internal
  tetap dari sisi Mikrotik, apa pun node VPN yang dipakai.
- Port RADIUS unik per-NAS (bukan 1812/1813 shared standar, pola MixRadius).
  Konsekuensi: skema `nas` di v0.6.1 sudah menyertakan kolom
  `auth_port`/`acct_port`/`coa_port` sejak migration pertama walau dynamic
  virtual server + port allocator baru dibangun di v0.6.5 — supaya tidak
  retrofit.

**Dependency wajib untuk v0.3.4** (dicatat saat v0.3.2 selesai): tabel
`subscriptions` yang lahir di v0.3.4 **harus** langsung menyertakan kolom
`reseller_id` (didenormalisasi dari `customers.reseller_id` saat subscription
dibuat, sama seperti pola yang sudah dipakai di `customers`) dan
`reseller_package_pricing_id`, diisi otomatis di Service layer — bukan
ditambahkan belakangan lewat migration alter terpisah. v0.3.2 sengaja tidak
menyentuh `subscriptions` sama sekali karena tabel itu belum ada di titik ini
(lihat `CHANGELOG.md` v0.3.2 untuk detail keputusan ini).

**Dependency wajib untuk v0.3.4** (dicatat saat v0.3.3 selesai): saat
`InvoiceService` dibuat, setiap invoice **harus** memanggil
`TaxCalculationService::calculateForAmount()` lalu
`TaxCalculationService::writeLedgerEntry()` — kontrak integrasi lengkap
(urutan panggilan, tipe parameter, contoh kode) didokumentasikan di
CLAUDE.md bagian "Tax engine integration contract (v0.3.4)". Tidak perlu
migration tambahan untuk ini — `reseller_tax_ledger.reference_type`/
`reference_id` sudah polymorphic generic sejak v0.3.3, tinggal diisi.
Dipenuhi di v0.3.4 — lihat `InvoiceService::generateForPeriod()`.

**Known limitation dari v0.3.4 (sengaja di-defer, bukan silent gap)**: tidak
ada proration. Subscription yang mulai/berhenti di tengah periode billing
tetap ditagih penuh satu periode. Kalau proration dibutuhkan, itu scope
sprint terpisah (kandidat: v0.3.5 atau versi Billing & Finance sesudahnya),
bukan retrofit diam-diam ke `InvoiceService::generateForPeriod()`.

**Dependency untuk v0.3.5 (Payment Gateway)**: `Invoice.status` sudah punya
state machine lengkap (`draft → pending → paid/overdue`, semua bisa
`cancelled`) via `InvoiceService`/`App\Enums\InvoiceStatus` — webhook
payment gateway v0.3.5 tinggal memanggil `InvoiceService::markPaid()`,
jangan `update(['status' => ...])` manual (melewati validasi transisi).

**Catatan teknis penting untuk SEMUA sprint berikutnya yang menambah query
tanggal** (ditemukan sebagai bug nyata saat membangun v0.3.4, mempengaruhi
kode v0.3.3 yang sudah ter-tag juga — lihat CLAUDE.md bagian "Cross-database
date comparison gotcha" untuk detail lengkap): jangan pernah
`->where('kolom_date', '<=', $tanggal->toDateString())` untuk kolom ber-cast
`'date'` — pakai `->whereDate(...)` selalu. SQLite (dipakai test suite) bisa
menyimpan kolom `date` dengan sufiks waktu, sehingga perbandingan string
biasa gagal persis di titik tanggal yang sama persis.

**Keputusan v0.3.5 yang perlu diketahui sprint berikutnya**: endpoint manual
`PATCH /api/v1/invoices/{invoice}/paid` (dari v0.3.4) **sengaja dipertahankan**
meski v0.3.5 membangun jalur otomatis via `PaymentService::handleWebhook()` —
dikonfirmasi eksplisit oleh Agung, bukan oversight. Konsekuensinya:
`InvoiceService::markPaid()` punya DUA jalur pemanggil yang sah (manual admin
tanpa verifikasi pembayaran, dan webhook Xendit dengan verifikasi penuh),
bukan cuma satu — kalau sprint berikutnya butuh audit trail lebih ketat
untuk pembayaran manual (mis. mewajibkan `payments` row juga dibuat utk
pembayaran manual, bukan cuma `update(['status'=>'paid'])` polos), itu
scope baru yang perlu dikonfirmasi eksplisit, dicatat sebagai backlog di
sini, bukan retrofit diam-diam.

**Out-of-scope v0.3.5, di-declare eksplisit sebagai backlog** (jangan
diasumsikan otomatis ada): WhatsApp payment notification (Baileys, nunggu
v0.4.0), auto-refund otomatis, retry payment flow (UI/service), partial
payment/cicilan, proration subscription (masih deferred dari v0.3.4 juga).

**Amendment v0.3.5 Fase H (dikonfirmasi Agung sebelum commit pertama, jadi
masuk branch yang sama, bukan sprint baru)**: UI Pengaturan > Payment
Gateway ditambahkan (ala referensi MikRadius) — mengubah dua kontrak inti
dari catatan lama di atas:
- `payments.channel_type` **bukan lagi** enum tetap `App\Enums\PaymentChannelType`
  (3 case: virtual_account/qris/invoice) — enum itu sudah dihapus. Sekarang
  varchar yang mereferensi `code` di katalog baru `payment_gateway_channels`
  (`code`/`label`/`category`/`enabled`, admin-managed lewat UI, tanpa hard FK
  — divalidasi di `PaymentService`). Menambah channel baru sekarang murni
  data (row baru di katalog), TAPI integrasi Xendit-nya (method baru di
  `XenditGatewayService` + arm baru di `PaymentService::createPaymentFor()`
  match-nya) tetap perlu kode — katalog v0.3.5 sudah memuat channel ewallet/
  retail_outlet/credit_card (OVO, DANA, Alfamart, dst) untuk checklist UI,
  TAPI belum ada integrasi API-nya (hanya bank_transfer_va/qris/invoice yang
  benar-benar bisa dipakai `createPaymentFor()`). Jangan asumsikan channel
  yang di-enable di checklist otomatis bisa dipakai — cek dulu.
- Kredensial Xendit (`XENDIT_SECRET_KEY`/`XENDIT_CALLBACK_TOKEN`) **pindah
  sumber runtime** dari `.env` ke `payment_gateway_settings` (DB, encrypted,
  singleton row id=1) via `PaymentGatewaySettingsService`. `.env` cuma
  dipakai sekali lagi lewat command manual `payment-gateway:import-env`
  untuk migrasi kredensial Fase A-G yang sempat di-set. Permission
  `payment_gateway_settings.*` super_admin-only (lebih ketat dari
  `invoices.*` yang juga dipegang `billing`), karena halaman ini memegang
  secret asli.

Detail lengkap ada di CLAUDE.md bagian "Payment gateway (Xendit, v0.3.5)".

## v0.4.0 — WhatsApp Gateway (Baileys, multi-sesi per reseller)

Deferred item dari v0.3.5 (WhatsApp payment notification) selesai di sprint
ini via hook `InvoiceService::markPaid()` -> `WhatsappGatewayService::buildAndQueue('payment_received', ...)`.

**Keputusan arsitektur final (jangan diasumsikan lain tanpa konfirmasi
ulang)**: satu nomor WhatsApp per reseller + satu sesi "direct" untuk
customer tanpa reseller; Node.js `whatsapp-gateway` container terpisah,
multi-session (Baileys, key = `session_key` = reseller_id sebagai string
atau literal `"direct"`); komunikasi Laravel<->Node via HTTP internal +
HMAC-SHA256 (bukan static token seperti Xendit) dengan timestamp + toleransi
5 menit; auth state per sesi di volume `whatsapp-gateway/auth_state/{session_key}/`;
outbound-only sprint ini (two-way/Chatwoot dideferred); template pesan bisa
di-override per reseller per event_type, fallback ke default ISP-level;
pengiriman lewat queue Redis bernama `whatsapp-{session_key}` (bukan satu
queue global) supaya sesi reseller yang disconnect tidak menghambat reseller
lain; rate limit (delay antar pesan, batch size, jeda antar batch, jadwal
batch harian) global untuk semua sesi, dikelola admin ISP.

**Gap yang ditemukan & keputusan yang diambil saat membangun (dikonfirmasi
Agung sebelum migration dijalankan)**:
- Flow registrasi pelanggan (`RegistrationService::register()`, v0.3.0)
  **tidak pernah mengisi `customer.reseller_id`** — jadi notifikasi
  `customer_registered` SELALU resolve ke sesi `"direct"`, tidak pernah ke
  sesi reseller manapun, walau secara bisnis ada reseller yang menaungi.
  Diterima apa adanya untuk sprint ini (bukan bug baru — behavior registrasi
  v0.3.0 tidak diubah, di luar scope v0.4.0). Kalau ini perlu diperbaiki
  (registrasi bisa atribusi reseller), itu scope baru untuk sprint terpisah.
- Tidak ada kolom `invoice_url`/`payment_link` tersimpan di mana pun
  (`payments.raw_response` jsonb ada tapi dari channel VA/QRIS tidak
  memilikinya, dan invoice belum tentu punya `Payment` row saat reminder
  dikirim). `{payment_link}` di template `invoice_due_reminder` di-generate
  **on-demand** saat `buildAndQueue()` dipanggil, lewat
  `PaymentService::createPaymentFor($invoice, 'XENDIT_INVOICE')` — kalau
  gagal (channel belum aktif, dsb), reminder tetap terkirim tanpa link
  (di-log sebagai warning, tidak pernah membatalkan pengiriman).
- `app/.env.example` (template dev non-Docker) masih `QUEUE_CONNECTION=database`
  sementara root `.env.example` (template Docker Compose yang sungguhan
  dipakai container) sudah benar `redis` sejak v0.1.0 — **bukan bug baru**,
  hanya `app/.env.example`-nya yang diselaraskan supaya konsisten dengan
  tech stack yang didokumentasikan; tidak ada perubahan pada deployment
  sungguhan.
- `whatsapp-{session_key}` adalah queue name dinamis (satu per reseller +
  "direct") yang tidak bisa dienumerasi statis di flag `--queue=` biasa.
  Container terpisah `boss-whatsapp-worker` menjalankan
  `whatsapp:queue-names` (artisan command baru) untuk membangun daftar queue
  saat ini, lalu restart `queue:work` tiap 5 menit (`--max-time=300`) supaya
  sesi reseller baru otomatis ikut terdengar tanpa restart manual — pola
  polling-restart yang sama dengan `boss-scheduler`.
- Literal session_key `"direct"` hanya unik selama deployment ini melayani
  SATU tenant ISP (asumsi operasional saat ini, sama seperti
  `payment_gateway_settings` — lihat CLAUDE.md). Kalau ini benar-benar jadi
  SaaS multi-tenant dengan banyak ISP berbagi satu `whatsapp-gateway`
  container, `"direct"` perlu diganti jadi key yang mengandung tenant_id —
  dicatat sebagai known limitation, bukan dikerjakan sekarang.

**Out-of-scope v0.4.0, di-declare eksplisit sebagai backlog**: two-way
messaging + integrasi Chatwoot (1 nomor gabungan WA + CS), overdue reminder
berulang (sengaja TIDAK ada — sekali lewat H-0 tanpa bayar, tidak ada
notifikasi WA lanjutan untuk invoice itu), notifikasi work order
teknisi/outage alert (nunggu modul teknisi/monitoring), rate limit
per-reseller (sprint ini rate limit global saja), auto-refund/retry payment
flow/partial payment/proration subscription (tetap deferred dari v0.3.4/
v0.3.5, belum masuk scope manapun).

Detail lengkap ada di CLAUDE.md bagian "WhatsApp Gateway (Baileys, v0.4.0)".

## v0.5.0 — Installation (Work Order Teknisi)

**Keputusan arsitektur final**: `odps`/`technicians`/`work_orders` tenant-scoped +
`reseller_id` nullable (null = milik ISP A langsung), pola identik dengan
`whatsapp_sessions`/`customers`. `OdpLocatorService::findNearestAvailable()` pakai
Haversine murni raw SQL (bukan PostGIS) — diverifikasi bekerja identik di SQLite
(driver test suite) maupun Postgres, termasuk fungsi `acos`/`radians`/`cos`/`sin`
yang ternyata tersedia di build SQLite PHP environment ini. `WorkOrderStatus`
adalah state machine linear (`pending_odp_check -> pending_verification -> ready
-> assigned -> in_progress -> completed`, dengan `odp_unavailable` sebagai
dead-end dari `pending_odp_check`, dan `cancelled` bisa dicapai dari status
non-terminal manapun) — tidak bisa loncat state (mis. `assigned -> completed`
tanpa `in_progress` ditolak dengan `InvalidWorkOrderStatusTransitionException`).

**Gap yang ditemukan & diputuskan saat membangun**:
- Migration `odps`/`odp_ports` tidak menyediakan endpoint terpisah untuk
  generate baris `odp_ports` — kalau tidak ditambal, sebuah ODP yang baru
  dibuat tidak akan pernah punya port sama sekali (tidak bisa dipakai
  `OdpLocatorService`). Ditambal dengan `Odp::provisionPorts()` (method
  eksplisit di model, BUKAN model event `created`) yang otomatis membuat
  port 1..`total_ports` berstatus `available`, dipanggil dari
  `OdpController::store()`. Sengaja bukan event `created` supaya
  `Odp::factory()->create()` di test tetap bisa dipakai independen dari
  `OdpPortFactory` tanpa collision di constraint `unique(odp_id,
  port_number)`.
- `WorkOrderService::complete()` awalnya mengecek kelengkapan foto/device
  SEBELUM mengecek legalitas transisi status — ditemukan lewat test sendiri
  ("illegal transition ... is rejected" gagal karena melempar
  `IncompleteWorkOrderException` duluan, bukan
  `InvalidWorkOrderStatusTransitionException`). Diperbaiki: legalitas
  transisi dicek PALING DULU, baru validasi kelengkapan foto/device.
- Disk `'local'` di Laravel 12 root-nya `storage/app/private/`, bukan bare
  `storage/app/` seperti diasumsikan di draft awal spec — detail versi
  framework, tidak mengubah keputusan "pakai disk local" itu sendiri.

**Out-of-scope v0.5.0, di-declare eksplisit sebagai backlog**: modul stok/
inventory barang riil (field `equipment_ready` di `work_orders` masih
placeholder manual, bukan dari data stok sungguhan), notifikasi WhatsApp
otomatis untuk work order (menyusul, terintegrasi dengan modul WhatsApp
Gateway v0.4.0 yang sudah ada), UI scan kamera html5-qrcode (endpoint API
`POST /work-orders/{id}/devices` sudah siap menerima hasil scan, komponen
kamera browser menyusul).

Detail lengkap ada di CLAUDE.md bagian "Installation / Work Order (v0.5.0)".
