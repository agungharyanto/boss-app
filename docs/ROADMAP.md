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
| v0.3.5  | Billing & Finance | Payment Gateway (Xendit)     | Integrasi Xendit (VA/QRIS/invoice), webhook handler + signature verification, idempotency, reconciliation | Backlog |
| v0.4.0  | Komunikasi      | Communication (Baileys)       | WhatsApp gateway, notifikasi group, routing area, OTP                                         | Backlog |
| v0.5.0  | Operasional     | Installation                  | Work order teknisi, scan MAC/serial, ODP/PON, foto instalasi                                  | Backlog |
| v0.6.0  | Network         | FreeRADIUS                    | Akun PPPoE via RADIUS, profile bandwidth, accounting (radacct), CoA/disconnect                | Backlog |
| v0.6.1  | Customer App    | Mobile Self-Service Portal    | Auth guard customer terpisah, ganti password (OTP), cek pemakaian, bayar tagihan               | Backlog |
| v0.7.0  | Network         | GenieACS                      | Binding ONT, SSID/password, RX power, reboot, provisioning                                    | Backlog |
| v0.8.0  | Network         | LibreNMS & Graph              | Device monitoring, graph jaringan, graph pemakaian per-pelanggan, alert                       | Backlog |
| v0.9.0  | Billing & Finance | Commission                   | Eligibility, approval, payment, clawback (menyempurnakan commission_ledger v0.3.0)             | Backlog |
| v0.10.0 | Network         | Outage Engine                 | ONT down detection, korelasi area, incident, maintenance                                      | Backlog |

Kita tidak loncat versi dalam satu cluster. Setiap versi selesai penuh
(lihat Definition of Done di RULES.md) sebelum lanjut ke versi berikutnya.
Urutan antar-cluster mengikuti dependency teknis: reseller sebelum
billing/tax (hindari retrofit), tax sebelum invoicing, invoicing sebelum
payment gateway, FreeRADIUS sebelum mobile app & usage graph.

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
