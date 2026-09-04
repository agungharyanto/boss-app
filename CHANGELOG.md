# Changelog

Format bebas mengikuti sprint di `docs/ROADMAP.md`. Setiap versi dicatat saat
tag dibuat (RULE BOSS-013).

## v0.9.7 — Login Terpadu (Admin + Referrer, 1 pintu di `/login`) (branch `login-terpadu`, merged + tagged `v0.9.7`)

Gabung 2 halaman login terpisah (`/login` Fortify email, `/referrer/login` custom HP) jadi **SATU pintu
di `/login`** — field pertama **"Email atau Nomor HP"** (bukan 2 field terpisah).

- `config/fortify.php` `'username' => 'login'` (dulu `email`), `'home' => '/'` (dulu `/dashboard`).
- `Fortify::authenticateUsing()` — deteksi email vs HP saat submit; email → jalur staff (`users`), HP →
  jalur Referrer (reuse `App\Support\LoginIdentifierResolver`, dipakai bareng `ReferrerLoginController`
  jalur compat). Verifikasi password di pemanggil, bukan resolver.
- Pesan gagal **identik** kedua jalur: `lang/id/auth.php` + `lang/en/auth.php` (baru) `failed` →
  "Email/nomor HP atau password salah." — tidak bocorkan identitas terdaftar / jalur mana.
- `App\Http\Responses\LoginResponse` (bind di `FortifyServiceProvider::register()`) → `redirect()->
  intended('/')`, biarkan route `/` yang branch (satu sumber kebenaran, `EnsureAdminPanelAccess::
  userHasAccess()`). Tidak hardcode redirect.
- Rate limit: `config('fortify.limiters.login')` diset → route middleware `throttle:login` (HTTP 429,
  5/menit per identifier+IP) untuk kedua jalur. `/referrer/login` POST compat tetap `throttle:6,1`.
- `/referrer/login` GET → 302 ke `/login`; POST tetap berfungsi (kompat link/bookmark lama).
  `ReferrerLoginController::logout()` redirect ke `route('login')`.
- Test: `UnifiedLoginTest` (13) + `ReferrerPortalLoginTest` disesuaikan. Diverifikasi live (staff email →
  `/dashboard`, Kamisem HP → `/referrer-portal`, wrong pw/unknown email → pesan generik sama).
- Merge v0.9.6: link **"Lupa password?"** (dibangun di v0.9.6, `/referrer/forgot-password`) dipindah ke
  form login terpadu `/login` (dulu cuma di halaman `/referrer/login` yang sekarang redirect).

---

## Portal Referrer diperluas + CPE offline-palsu + link "Ganti Modem" (bagian dari tag `v0.9.6`)

**BAGIAN A — Portal Referrer: daftar SEMUA pelanggan (keputusan Agung).** "Pelanggan yang Saya
Referensikan" → **"Daftar Pelanggan"** (semua, tenant-scoped) — titip cash bisa dikumpulkan siapa saja,
tidak harus Referrer resmi.
- `ReferrerTitipService::availabilityFor(Customer)` — syarat "direferensikan Referrer ini" **DIHAPUS**;
  sisa: `ppp_package_id` + `CommissionRate` aktif dengan `titip_amount`. `existingForMonth(Customer)` cek
  per-pelanggan (siapa pun), bukan per-acting-referrer.
- `Dashboard` Livewire: query `Customer::query()` (bukan `$referrer->referrals()`) + paginasi + search
  (nama/CID/HP). Kolom: Nama, **CID** (`customers.cid`, sudah ada), Alamat, Paket, **Referensi**
  (Referrer resmi — konteks, bukan filter).
- **Rekap dipecah 2 tabel**: "Rekap Komisi" (`scheme != titip`) dan "Rekap Titip" (`scheme = titip`).
- Komisi Titip tetap diatribusi ke Referrer yang **MENCATAT** (acting), bukan Referrer resmi.

**BAGIAN B — CPE "offline palsu".** Root cause: `CpeDeviceStatusSyncService` ambang online cuma 5 menit +
probe gagal langsung = Offline → ONT dengan `PeriodicInformInterval` panjang (1-12 jam) + `connection_request`
gagal salah dicap Offline tiap siklus. "Ganti Modem" (re-input SN sama) set Online tanpa cek kesegaran →
itu yang "memperbaiki".
- Fix: ambang online → `config('services.cpe.online_threshold_minutes')` default **180 (3 jam)**; probe
  gagal HANYA set Offline kalau Inform terakhir > `offline_hard_cutoff_minutes` default **1440 (24 jam)**
  atau tidak pernah ada — di antara: status TIDAK diubah.
- Fix scheduler drift: `->runInBackground()->withoutOverlapping()` pada `SyncCpeDeviceStatus`/
  `SyncCpeSignalHistory`/`SyncContainerStats` — entrypoint `while true; schedule:run; sleep 60` diblokir
  command foreground lama → `cpe:sync-device-status` jalan ~tiap jam bukan 15 menit, `cpe:reconcile`
  ~tiap 20 menit bukan 5.
- **Diverifikasi live**: sync ulang → Offline `26 → 13` (13 device pulih dari false-offline).

**BAGIAN C — link "Ganti Modem".** `customer-show.blade.php` link dari `route('web.cpe-devices.index')`
(daftar umum) → `route('web.cpe-devices.show', $cpeDevice)` (detail device yang ter-bind).

**BAGIAN D — ROADMAP v0.23.0 OMCI** dikoreksi: hapus SmartOLT-sebagai-pendekatan; tegaskan integrasi
LANGSUNG ke OLT (API/CLI native vendor), 3 jalur provisioning ke depan (TR-069/OMCI/DHCP Option 43).

Test: `ReferrerTitipPortalTest` (13, disesuaikan+baru), `CpeDeviceStatusSyncServiceTest` (+2 baru),
`CustomerShowAddDeviceTest` (+1 baru).

---

## v0.9.6 — Fitur Titip (Self-Service + OTP WhatsApp) (branch `v0.9.6-fitur-titip`, belum di-merge/tag)

**Referrer mencatat sendiri pembayaran cash "titip" dari pelanggan yang ia referensikan → dapat komisi
Titip.** Desain final dikonfirmasi Agung: langsung `Eligible` setelah OTP (bukan approval admin), nominal
dikunci ke `CommissionRate.titip_amount`, CREATE-ONLY, tidak ada otomasi NAS/RADIUS/MixRadius (perpanjangan
tetap manual admin).

**Langkah 1 — perbaikan fondasi:**
- `App\Enums\CommissionScheme::Titip` ditambahkan. BEDA sifat dari `Recurring`/`LimitedCount`: bukan skema
  *atribusi* pelanggan (tidak dipilih admin saat registrasi), murni dari Portal Referrer.
  `CommissionRate::amountForScheme('titip')` + `CommissionRate::titipAmount()` di-wire; `schemeOptions()`
  **tetap tidak** menawarkan Titip (itu form registrasi/edit pelanggan).
- `CommissionLedgerMaturityService::matureForPaidInvoice()` mengecualikan baris `scheme=titip` dari lookup
  "template" — baris Titip tidak pernah jadi template, tidak pernah di-append per invoice lunas.
- `ReferrerReferralResource` (`GET /api/v1/referrals`): field tunggal `commission_status`/`commission_amount`
  **dihapus** (breaking), diganti `commissions[]` (SEMUA baris) + `commission_total_earned`. `->first()`
  sudah salah sejak v0.9.5.
- Migration `2026_09_02_140000_add_payment_period_to_commission_ledger_table.php`: `commission_ledger.
  payment_period` (date, nullable — tanggal 1 bulan pembayaran) + indeks `(referrer_id, customer_id,
  payment_period)`. Alasan: baris Titip `invoice_id` NULL, indeks unik parsial v0.9.5 tidak melindunginya
  dari duplikat; `payment_period` memberi dimensi "bulan apa" untuk guard duplikat app-layer.

**Langkah 2 — OTP WhatsApp untuk Referrer:**
- `App\Enums\WhatsappEventType::ReferrerActionOtp` (`referrer_action_otp`) — **event type ke-5**, explicit
  permission Agung. Penerima = **Referrer** (`referrers.phone`), bukan pelanggan.
- `WhatsappGatewayService::buildAndQueueForReferrer()` — jalur terpisah dari `buildAndQueue()` (yang wajib
  `Customer`): template di-resolve di level ISP (reseller_id null), pesan selalu diantre lewat sesi
  "direct". Variabel: `{referrer_name}`, `{otp_code}`, `{otp_minutes}`, `{customer_name}`, `{company_name}`.
  Default template di-seed `WhatsappMessageTemplateSeeder`.
- `App\Services\Commission\ReferrerActionOtpService` — kode 6-digit, cache 5 menit (plaintext, Redis
  internal, sama posture `ScriptDownloadTokenService`), maks 5 salah → kode dihapus, single-use.
  `RateLimiter` kirim ulang 3×/10 menit per `(referrer, scope)`. `$scope` menyertakan `customer_id`
  spesifik. `ReferrerOtpException` (pesan user-facing Indonesia).

**Langkah 3 — form Titip di Portal Referrer:**
- `App\Livewire\ReferrerPortal\Dashboard` diperluas: tombol "Catat Titip" hanya untuk pelanggan yang
  punya `ppp_package_id` + `CommissionRate` aktif dengan `titip_amount` (via
  `App\Services\Commission\ReferrerTitipService::availabilityFor()`). Alur modal: konfirmasi detail
  (nama/alamat/paket/nominal) → `sendTitipOtp()` → input kode → `submitTitip()` (verifikasi OTP → `record()`).
  Guard duplikat bulan berjalan = checkbox konfirmasi (bukan hard block). Rekap Komisi diisi (SEMUA baris
  `commission_ledger` milik referrer). Tidak ada aksi edit/hapus (CREATE-ONLY).
- `ReferrerTitipService::record()` → `commission_ledger` baris baru `scheme=titip status=eligible`,
  `amount` dari rate, `payment_period` = tanggal 1 bulan berjalan, `invoice_id` NULL. Tenant-eksplisit.
- **Belum ada padanan REST** — akun portal referrer tidak punya Sanctum token; menambah penerbitan token
  untuk akun referrer = keputusan tersendiri. Business logic ada di service layer, bisa dipanggil dari mana
  pun nanti.

**Langkah 4 — dashboard admin "Titip Masuk":**
- `App\Livewire\Commission\TitipMasukIndex` (`/titip-masuk`) — daftar read-only semua `commission_ledger`
  `scheme=titip`, filter status + cari nama pelanggan/referrer, paginasi. **Murni daftar kerja
  operasional** (admin perpanjang layanan manual di MixRadius) — TIDAK ada tombol approve/reject.
- Permission baru `commission_ledger.view` (`seedCommissionLedgerPermissions()`, tier-admin-only) +
  `CommissionLedgerPolicy` (auto-discovered). Benih untuk UI admin komisi v0.9.7.
- Link sidebar di cluster "Billing & Finance" setelah "Rate Komisi". Diverifikasi HTTP nyata (login
  superadmin → `/titip-masuk` 200, link ada di `/dashboard`).

**Test:** `ReferrerTitipPortalTest` (9), `CommissionSchemeTitipTest` (5), `TitipMasukIndexLivewireTest` (6).
`RegistrationApiTest` disesuaikan ke bentuk `commissions[]`.

**Lupa Password Portal Referrer (digabung ke sprint ini — reuse infra OTP Langkah 2):**
- `App\Livewire\Auth\ReferrerForgotPassword` (`GET /referrer/forgot-password`, `guest` middleware, link
  "Lupa password?" di `/referrer/login`) — multi-tahap: Nomor HP → OTP WhatsApp → password baru (2×,
  `Password::defaults()` + `confirmed`). Reuse `ReferrerActionOtpService` dengan scope
  `"password_reset:{referrerId}"` — **beda cache key** dari scope `"titip:{customerId}"`, jadi kode reset
  password ↔ kode Titip terisolasi penuh (ditest 2 arah).
- **Anti-enumerasi**: nomor HP tidak terdaftar / Referrer non-aktif → pesan generik yang SAMA ("Kalau nomor
  ini terdaftar, kode ... telah dikirim"), selalu maju ke tahap OTP, tidak ada WA log, tidak ada error
  spesifik. Referrer id yang cocok disimpan di **session** (server-side), bukan properti Livewire.
- Rate limit kirim ulang = 3×/10 menit (sama `ReferrerActionOtpService`, sama fitur Titip).
- Password baru → `User::forceFill(['password' => Hash::make()])->save()`. Session verifikasi kedaluwarsa
  10 menit setelah OTP benar.
- **Belum ada padanan REST** (login portal Referrer sendiri belum ada REST).
- Layout baru `layouts/referrer-guest.blade.php` (halaman guest portal Referrer, sebelumnya login pakai
  blade standalone).

**Reset password Kamisem (id=4)** dilakukan manual via tinker (belum ada aksi admin regenerate password
untuk Referrer yang sudah punya akun — hanya ada saat create / `generateLoginAccount` untuk yang
`user_id` null). Fitur "Lupa Password" di atas menutup kebutuhan ini ke depan untuk sisi Referrer.

**Test:** `ReferrerForgotPasswordTest` (6) — reset+login berhasil, nomor tak terdaftar tidak bocor,
isolasi scope password_reset↔titip, kode salah ditolak, rate limit.

**Amendment (setelah investigasi OTP gagal kirim, 2026-09-02):**
- `whatsapp_message_logs` id=2 (OTP ke Kamisem) `failed` karena sesi WhatsApp **"direct" belum connected**
  (`HTTP 502: session "direct" is not connected (status=qr_pending)`) — BUKAN bug kode. Sesi "direct"
  wajib di-scan QR dulu sebelum alur OTP Referrer jalan.
- Bug kecil ditemukan & diperbaiki: template default `referrer_action_otp` awal meng-hardcode frasa
  Titip + `*{customer_name}*`, jadi pesan Lupa Password (yang `$relatedCustomer`-nya null) berbunyi
  "...pelanggan **. Berlaku...". Diganti pakai variabel baru **`{action_label}`** —
  `ReferrerActionOtpService::issue()` sekarang wajib param `$actionLabel` (Titip: "mencatat titip
  pembayaran untuk {nama}"; Lupa Password: "reset password akun Portal Referrer"). `WhatsappMessageTemplateSeeder`
  + 15 baris template di DB dev sudah di-update.
- Merge `main` ke branch ini (hotfix `WhatsappMessageLog::scopeKnownEventType()` + lainnya) — bersih,
  tanpa konflik.

**Amendment kedua (investigasi timeout kirim WhatsApp, 2026-09-02 → dikoreksi 2026-09-03):**
- Gejala: OTP `failed` dengan `cURL error 28: timed out`, `sock.sendMessage()` hang 60s.
- **Diagnosis 2026-09-02 (KELIRU): "nomor di-restrict WhatsApp".** SALAH.
- **Akar masalah SEBENARNYA (2026-09-03): FORMAT NOMOR.** Bug laten sejak v0.4.0:
  `whatsapp-gateway/src/sessionManager.js` `toJid()` cuma strip non-digit — nomor lokal `087884374939`
  jadi `087884374939@s.whatsapp.net` (JID tidak sah) → Baileys hang resolve → timeout. Dibuktikan:
  `onWhatsApp("6287884374939")` → `exists:true` 380ms; kirim ke `6287884374939@s.whatsapp.net` **SUKSES
  ~0.3s**; full jalur Laravel `SendWhatsappMessageJob::handle()` → `status=sent`.
- **Fix format** (2 tempat): `toJid()` normalisasi Indonesia (`0xxx`→`62xxx` dst); `App\Support\
  WhatsappPhone::normalize()` dipakai `WhatsappGatewayService` saat isi `whatsapp_message_logs.
  phone_number`. `WhatsappPhoneTest` unit.
- **Fix robustness tetap dipertahankan** (hardening yang benar, bukan akar masalah): `sendMessage`
  fast-fail 20s + cek `sock.user.id`; reconnect exponential backoff; `DisconnectReason.badSession`
  di-handle; `markOnlineOnConnect:false`; `SendWhatsappMessageJob` timeout 30→35s.
- OTP ke Kamisem **sekarang benar-benar terkirim** — tidak ada aksi manual yang diperlukan lagi.

---

## v0.9.5 — Commission Ledger Auto-Maturity, APPEND per invoice (branch `v0.9.5-commission-auto-maturity`, redesain 2026-09-02, belum di-merge/tag)

**Redesain (dikonfirmasi Agung): komisi diperoleh PER INVOICE LUNAS, bukan sekali flip.**

`App\Services\InvoiceService::markPaid()` (satu-satunya jalur sah "invoice lunas" — PATCH manual v0.3.4 +
webhook Xendit v0.3.5) memanggil `App\Services\CommissionLedgerMaturityService::matureForPaidInvoice($invoice)`.
Setiap invoice pelanggan yang lunas:

- **Skema `recurring`**: satu baris `commission_ledger` **Eligible** per invoice lunas, tanpa batas —
  komisi bulanan berlanjut selama pelanggan bayar.
- **Skema `limited_count`**: sama (satu baris per invoice lunas), tapi **di-cap ke
  `CommissionRate::limited_count_times`**. Setelah N baris komisi diperoleh (Eligible/Approved/Paid),
  invoice lunas berikutnya **tidak lagi** menghasilkan baris.
- **Skip pembayaran** (invoice tidak pernah lunas): `markPaid()` tidak dipanggil → tidak ada baris
  komisi. **"Gugur, bukan tertunda" tercapai natural — nol logic tambahan.**

**Baris "template" v0.9.4** (dibuat saat registrasi / saat admin men-set referrer — Pending,
`invoice_id` NULL): invoice **pertama** yang lunas mematangkan baris INI di tempat (Eligible +
`invoice_id`, `amount` di-refresh dari rate **saat ini**, bukan nilai template lama). Invoice berikutnya
baru genuinely membuat baris baru (append). Template **tanpa scheme** (referrer diisi tanpa memilih skema)
tidak pernah menghasilkan komisi sampai admin melengkapi skema-nya.

**Migration** `2026_09_02_120000_add_invoice_id_to_commission_ledger_table.php`:
- `commission_ledger.invoice_id` (nullable FK → `invoices`, `nullOnDelete` — hapus invoice tidak
  menghapus jejak komisi yang sudah diperoleh referrer).
- **Indeks unik parsial** `WHERE invoice_id IS NOT NULL` — satu invoice = maksimal satu baris komisi
  (penjaga idempotensi lapis DB; banyak baris template `invoice_id` NULL tetap boleh berdampingan).

**Idempoten berlapis**: (1) unik parsial DB, (2) cek `where('invoice_id', $invoice->id)->exists()` di
service, (3) `InvoiceService::transition()` sudah menjamin `markPaid()` menang sekali (`Paid→Paid`
ditolak state machine).

**`subscriptions` / `SubscriptionService` / `GenerateDueInvoices` TIDAK disentuh** — service ini murni
membaca `$invoice->customer_id` + `$customer->ppp_package_id` (v0.9.4, independen dari billing) +
`CommissionRate`. Tenant-eksplisit, tidak bergantung `Auth` (jalur webhook Xendit).

**Test**: `CommissionMaturityTest` (10 kasus — matang template di tempat, amount dari rate saat ini bukan
template lama, recurring append per invoice, limited_count berhenti di cap, template tanpa scheme tak
pernah generate, skip = tak ada baris, idempotensi 1 invoice = 1 baris, no-referral = no-op, pelanggan
lain / tenant lain tak terpengaruh). **Full regression suite** (lihat commit). Pint clean. Belum
di-merge/tag.

**DB dev**: migration BELUM dijalankan di DB dev (0 invoice, 1 `commission_ledger`, 1 `commission_rate`).
Kalau branch dibatalkan: `migrate:rollback --step=1`.

## v0.9.4 — Skema Komisi per Pelanggan (branch `v0.9.4-skema-komisi-per-pelanggan`, implementasi selesai 2026-09-01, belum di-merge/tag)

**Catatan status**: branch dari `develop` (`291a8ca`), **bukan `main`** — karena butuh pemindahan
Referrer/Rate Komisi (`74cb16a`) yang masih di `develop` saja. **DB dev sudah di-`migrate`** (2 kolom baru)
untuk verifikasi HTTP; kalau branch dibatalkan: `migrate:rollback --step=2`. Belum di-merge/tag.

**KONSTRAIN yang dipegang ketat**: **`subscriptions`, `SubscriptionService`, `GenerateDueInvoices` TIDAK
disentuh sama sekali.** Billing pelanggan masih manual/MixRadius — BOSS App belum jadi sumber tagihan.
`customers.ppp_package_id` yang dibangun di sini **HANYA** untuk menautkan komisi, sepenuhnya independen
dari `subscriptions`.

### Langkah 0 (investigasi) — temuan kunci

- `customers.package` (varchar, v0.3.0) = **field write-only mati**: ditulis form registrasi, **NOL
  pembacaan** di mana pun (detail pelanggan, list, invoice, `{package_name}` WA = `subscription->name`
  bukan ini, export, dashboard — semua nol). 551 customers, **semua `package = NULL`**, 0 `referred_by_
  referrer_id`. Blast radius ganti→FK: ~5 file, semua jalur registrasi.
- `subscriptions` = modul lengkap (v0.3.4) tapi **tidak punya `ppp_package_id`**, 0 baris, registrasi
  tidak pernah bikin subscription. Sengaja tidak disentuh.

### Langkah 1 — migration & model

- `2026_09_01_220000` — `customers.ppp_package_id` (FK→`ppp_packages`, nullable, `nullOnDelete`).
  `customers.package` **TIDAK di-drop** (nol data, minim risiko), cuma berhenti dipakai.
- `2026_09_01_220100` — `commission_ledger.scheme` (varchar nullable, `App\Enums\CommissionScheme`:
  `recurring` / `limited_count`). `amount` (ada sejak v0.3.0) baru mulai benar-benar diisi di sini.
- `App\Enums\CommissionScheme` (baru). `Customer::pppPackage()` BelongsTo + `ppp_package_id` fillable.
  `CommissionLedger`: `scheme` fillable + cast. `CommissionRate::schemeOptions()` (opsi tersedia — hanya
  amount non-null, **Titip tidak termasuk**) + `amountForScheme()`.

### Langkah 2 — form registrasi

- `RegisterCustomer` (Livewire): input teks "Paket" → **dropdown `PppPackage` aktif** (`is_active=true`),
  disimpan ke `customers.ppp_package_id`.
- Dropdown **"Skema Komisi" reaktif** (`wire:model.live` di paket + referrer) — muncul HANYA kalau:
  referrer dipilih **DAN** paket dipilih **DAN** `CommissionRate` paket itu aktif dengan ≥1 dari
  `recurring_amount`/`limited_count_amount`. Opsi: "Per Bulan" (jika recurring) dan/atau "{N} Kali" (jika
  limited, N = `limited_count_times`). **"Titip" tidak pernah ditampilkan** (di luar scope).
- `RegistrationService::register(array, ?Referrer, ?string $scheme)` — param `$scheme` baru. Pembuatan
  `CommissionLedger` di-ekstrak ke **`App\Services\CommissionAttributionService::createPendingLedger()`**
  (dipakai bareng edit pelanggan). Kalau `$scheme` diisi & rate punya amount-nya → `scheme` + `amount`
  terisi; kalau `$scheme` null (admin skip) → **`scheme = NULL, amount = NULL`, persis perilaku lama**
  (backward compatible, tidak maksa). Jaring pengaman: `$scheme` diisi tapi rate tidak punya amount untuk
  skema itu → tetap `scheme/amount` NULL, tidak error, tidak menebak.
- API `POST /registrations`: rule `package` diganti `ppp_package_id` (nullable, exists tenant-scoped) +
  `scheme` (nullable, `in:recurring,limited_count`). `RegistrationController` meneruskan `$scheme` terpisah.

### Langkah 3 — edit pelanggan existing

- Panel baru **"Paket & Referral"** di `CustomerShow` (pola sama `editingProfile`/`updateProfile`):
  `startEditingCommission()` / `updateCommissionAttribution()` — field Paket (dropdown), Referrer
  (dropdown, default "Tidak ada referral"), Skema Komisi (reaktif, sama seperti Langkah 2).
- **Aturan atribusi**:
  - `referred_by_referrer_id` **null → terisi**: field Referrer editable → buat 1 `CommissionLedger`
    Pending baru (logic sama dg registrasi, scheme+amount kalau skema dipilih).
  - **Referrer SUDAH terisi → opsi (c) diterapkan: field Referrer TERKUNCI** (lihat amendment di bawah).
    Hanya field Paket yang bisa diubah untuk pelanggan yang sudah punya referrer; `commission_ledger`
    tidak pernah dibuat/diubah/di-void dari panel ini.

### Amendment — opsi (c) "lock field Referrer" diterapkan + nominal Rupiah di label skema (2026-09-01)

**1. Label dropdown "Skema Komisi" menyertakan nominal Rupiah.** `CommissionRate::schemeOptions()` (SATU
sumber kebenaran — dipakai form registrasi & panel edit, nol duplikat format currency) sekarang
menghasilkan label `"Per Bulan - Rp 3.000"` / `"2 Kali - Rp 33.000"` (ribuan pakai titik, tanpa desimal,
lewat helper privat `formatRupiah()` = `number_format(..., 0, ',', '.')`). Diverifikasi terhadap rate
nyata paket `HomeFixed-10Mbps` (#6): recurring 3000, limited 33000×2.

**2. Skenario terbuka "ganti referrer existing" → diputuskan pakai opsi (c): field Referrer terkunci
setelah terisi.** Di `CustomerShow`:
- `referrerLocked()` = `customer.referred_by_referrer_id !== null`. Kalau terkunci, blade merender
  Referrer sebagai input **disabled** (nama referrer + catatan "Referrer terkunci setelah diisi — tidak
  bisa diganti/dihapus dari sini"), bukan `<select>`.
- `updateCommissionAttribution()` **mengabaikan total** `editReferrerId` dari client kalau terkunci
  (`$newReferrerId = $hadReferrer ? nilai_lama : $this->editReferrerId`) + tidak memvalidasi/memakainya
  — defense in depth terhadap wire request yang dibuat-buat. Field Skema Komisi juga otomatis tidak
  muncul saat terkunci (tidak ada ledger baru yang akan dibuat).
- Alasan: mengganti/menghapus referrer yang sudah ada mengubah jejak atribusi/komisi — bukan aksi inline
  biasa. Koreksi (kalau perlu) = jalur admin tersendiri, belum dibangun. Sejalan dg prinsip "append-only,
  koreksi lewat entri baru" (CLAUDE.md v0.9.2 portal referrer).
- **Kalau Agung sebenarnya mau opsi (a) atau (b)** — perubahannya kecil & terisolasi di `CustomerShow` +
  blade + 2 test; tinggal bilang.

Test terkait diupdate: `test_an_existing_referrer_is_locked_and_cannot_be_changed`,
`test_a_locked_referrer_cannot_be_cleared_but_the_package_can_still_change`,
`test_scheme_labels_include_the_rupiah_amount_from_the_rate` (+ asersi label lama disesuaikan ke format
Rupiah).

### Test

- `RegistrationServiceTest` — split jadi test terpisah: perilaku LAMA (referrer, tanpa skema → amount
  NULL) **dipertahankan**, + BARU (recurring / limited_count → amount dari rate) + jaring pengaman.
- `RegistrationApiTest` — +3 (ppp_package+scheme isi amount, scheme invalid ditolak, paket lintas-tenant
  ditolak); perilaku lama (amount/scheme NULL) diperketat asersinya.
- `RegisterCustomerLivewireTest` — +4 (dropdown skema hanya opsi tersedia, field hidden tanpa referrer,
  registrasi dg skema isi amount, tanpa skema tetap null).
- `CustomerShowCommissionTest` (file baru, 4) — null→set bikin ledger, +scheme isi amount, ganti referrer
  existing TIDAK bikin ledger, hapus referrer TIDAK bikin ledger.
- **29 test terarah hijau.** Full regression suite: (lihat commit).

### Verifikasi HTTP nyata (`https://boss.bajastu.id`, `super_admin`)

- `/customers/register`: dropdown "Paket" berisi `HomeFixed-10Mbps` (`wire:model.live="ppp_package_id"`),
  input teks lama hilang; "Tidak ada referral" default; "Skema Komisi" **tidak muncul** sebelum
  referrer+paket dipilih. Setelah paket+referrer di-set (via Livewire nyata) → field muncul dengan opsi
  **"Per Bulan"** + **"2 Kali"** (dari rate paket #6: recurring 3000, limited 33000×2), **tanpa opsi
  Titip**.
- `/customers/434`: panel "Paket & Referral" + tombol "Edit paket & referral" ter-render.

## Restrukturisasi Sidebar (branch `restrukturisasi-sidebar` + v0.9.3, digabung ke `develop`/`main` 2026-09-01)

**Catatan status**: branch `restrukturisasi-sidebar` dari `main` (`1a07b2e`). Murni UI — nol perubahan
backend/route kecuali **menghapus 1 link menu**. **Digabung**: `v0.9.3-commission-rate-settings` +
`restrukturisasi-sidebar` di-merge (`--no-ff`) ke `develop` lalu `main` (`d56be0b`), konflik
`sidebar.blade.php` (baris `active` cluster Operasional) + `CHANGELOG.md` diselesaikan manual
mempertahankan kedua sisi. Full suite 1382 hijau pasca-merge.

**Perubahan lanjutan pasca-merge (commit `74cb16a` di `develop`, BELUM di `main`, menunggu verifikasi
Agung)**: link **"Referrer"** + **"Rate Komisi"** dipindah dari cluster **"Operasional"** ke **"Billing &
Finance"** (di bawah grup "Profil Paket") — keduanya konsep komisi/keuangan. `active` kedua cluster
diupdate. **Cluster "Operasional" sekarang tinggal 1 item ("Reseller")** — SENGAJA dibiarkan 1-item;
keputusan membubarkan cluster / memindah "Reseller" ke tempat lain **menunggu konfirmasi Agung**, tidak
diputuskan sepihak. `SidebarNavigationTest` +2 test (15/15 hijau). Diverifikasi HTTP nyata: Billing &
Finance kini berisi Tax Components / Reseller Tax Policy / Subscriptions / Invoices / Payment
Reconciliation / Profil Paket (+5 child) / Referrer / Rate Komisi — semua 1 section; Operasional cuma
Reseller.

### Investigasi komponen sidebar (Langkah 2) — dijawab sebelum ubah perilaku

- **Q1 — NAS / Perangkat CPE / Profil Paket saat ini semua berperilaku IDENTIK.** Parent row grup
  `children` (v0.8.1) dipecah jadi 2 target klik terpisah: (a) label teks = `<a href="{{ route($link['route']) }}">`
  sungguhan → klik = navigasi ke halaman itu (NAS→`/nas`, CPE→`/cpe-devices`, Profil Paket→`/bandwidth-profiles`);
  (b) tombol chevron kecil = `x-on:click="subOpen = !subOpen"` → cuma toggle child, tanpa navigasi.
  Jadi BUKAN "klik = navigasi DAN toggle sekaligus" — label & chevron target berbeda, tidak overlap.
  Tidak ada satu pun yang toggle-murni; ketiganya link ke halaman lewat label-nya.
- **Q2 — komponen sidebar SEKARANG TIDAK punya kemampuan bikin parent toggle-murni.** Cabang
  `@if (! empty($link['children']))` selalu render label parent sebagai `<a href="{{ route($link['route']) }}">`
  — selalu butuh & pakai key `route`. Tidak ada jalur kode untuk parent `children` tanpa route.
  Kemampuan ini **genuinely perlu ditambah** — dan ditambah di sini, mendukung 2 tipe parent supaya
  section lain tidak ikut berubah.

### Perubahan

1. **Link "Package Pricing" (`reseller_package_pricing`) dihapus dari sidebar** (section Operasional) +
   referensi `web.reseller-package-pricing.*` dari `active` cluster itu. **Route/controller/model/service/
   policy/test-nya TIDAK disentuh** (di luar scope, resiko ke fitur reseller lain). Dicek dengan grep:
   `sidebar.blade.php` adalah **satu-satunya** file UI yang mereferensikan route ini — tidak ada tombol/
   redirect/widget lain yang mengarah ke sana.
2. **Komponen sidebar menambah tipe parent "toggle-murni"**: sebuah link `children` **tanpa** key `route`
   (flag `toggle_only`) di-render sebagai satu `<button>` (label + chevron jadi satu, klik = expand/collapse,
   nol navigasi). Tipe lama "link-and-toggle" (punya `route`) tetap dipakai NAS/Perangkat CPE, tidak berubah
   sama sekali.
3. **Grup "Profil Paket" pindah** dari cluster "Network" → "Billing & Finance" (harga jual/modal paket =
   konsep billing). `active` kedua cluster diupdate. Nama grup tetap "Profil Paket".
4. **"Profil Paket" jadi toggle-murni** (`toggle_only => true`, key `route` dihapus). "Bandwidth Profile"
   (dulu jadi link parent) kini jadi **child pertama** (5 child: Bandwidth Profile, IP Pool Pelanggan,
   Grup Profil, Profil Hotspot, Profil PPP). Gate grup tetap `viewAny(BandwidthProfile)` (5 permission
   selalu diberikan bersamaan via `giveToAdminTier`); tiap child tetap punya `can()` sendiri.
5. **Semua grup collapsible DEFAULT TERTUTUP** saat halaman pertama dibuka — `x-data` diubah dari
   `localStorage.getItem(...) !== 'false'` (default terbuka) jadi
   `{{ $active ? 'true' : 'false' }} || localStorage.getItem(...) === 'true'` (default tertutup).
   Pengecualian: grup yang route aktifnya ada di dalamnya di-auto-buka (`$cluster['active']` yang sejak
   dulu dihitung tapi tidak pernah dipakai, kini dipakai; `$subActive` dihitung baru untuk sub-grup dari
   route parent + semua child). Pilihan manual user (`localStorage === 'true'`) tetap dihormati untuk grup
   yang tidak sedang aktif. **Trade-off**: grup yang sedang aktif selalu menang atas localStorage — user
   tidak bisa mem-persist "tutup" untuk cluster halaman yang sedang dibuka (dianggap wajar — user butuh
   konteks posisi).

### Test

`SidebarNavigationTest` — 3 test lama diupdate (Profil Paket kini di `/invoices`-context, parent =
toggle-murni, Bandwidth Profile jadi child), 5 test baru:
`test_profil_paket_parent_is_a_pure_toggle_not_a_link` (regex: `<button aria-controls="sidebar-subgroup-profil-paket"><span>Profil Paket</span>`,
+ negatif: markup `<a class="flex-1">` lama tidak lagi membungkus "Profil Paket"),
`test_profil_paket_sits_in_billing_finance_not_network` (posisi byte: Billing&Finance < Profil Paket < Network),
`test_package_pricing_link_is_gone_from_the_sidebar`,
`test_sidebar_clusters_default_collapsed_except_the_active_one` (assert string `x-data` init: aktif =
`open: true || ...`, lain = `open: false || ...`),
`test_profil_paket_subgroup_auto_opens_on_its_own_pages` (`/hotspot-packages` → `subOpen: true || ...`,
`/invoices` → `subOpen: false || ...`). **13/13 hijau.**

### Verifikasi HTTP nyata (`https://boss.bajastu.id`)

- `super_admin@boss.local` → `GET /invoices` 200: "Package Pricing" **0 kemunculan**; "Profil Paket"
  1×, di-render sebagai `<button ...aria-controls="sidebar-subgroup-profil-paket"><span>Profil Paket</span>`
  (bukan `<a>`); posisi byte antara header "Billing & Finance" (@8119) dan "Network" (@16820); 5 child
  link (bandwidth-profiles/customer-ip-pools/network-profile-groups/hotspot-packages/ppp-packages) semua
  ada; cluster `billing-finance` `x-data="{ open: true || ...}"`, `network`/`pelanggan` `open: false || ...`.
- `customer_service@boss.local` → `GET /customers` 200: tidak ada "Profil Paket" maupun "Package Pricing".

### Langkah verifikasi manual untuk Agung

1. Login admin → **hard-refresh** (Ctrl+Shift+R) `/dashboard` → sidebar: **semua grup tertutup** (cuma
   header + chevron kelihatan). Klik "Billing & Finance" → terbuka; di dalamnya ada "Profil Paket" (paling
   bawah) + chevron.
2. Klik "Profil Paket" → **TIDAK pindah halaman** (URL tetap `/dashboard`), cuma daftar 5 child muncul
   (Bandwidth Profile / IP Pool Pelanggan / Grup Profil / Profil Hotspot / Profil PPP). Klik lagi → tutup.
3. Klik "Bandwidth Profile" (child) → baru pindah ke `/bandwidth-profiles`. Perhatikan: grup "Billing &
   Finance" + sub-grup "Profil Paket" **auto-terbuka** (karena halaman aktif), grup lain tetap tertutup.
4. Cek cluster "Network": "Profil Paket" **tidak ada lagi** di situ (cuma NAS / OLT / Monitoring /
   Perangkat CPE). NAS & Perangkat CPE tetap seperti dulu — klik label = pindah halaman, klik chevron =
   toggle.
5. Cek "Package Pricing" **hilang total** dari sidebar (dulu di "Operasional", di bawah "Reseller").
   Halaman `/reseller-package-pricing` sendiri masih ada kalau diketik manual (route tidak dihapus).
## v0.9.3 — Commission Rate Settings (implementasi selesai 2026-09-01, belum di-merge/tag)

**Catatan status**: branch `v0.9.3-commission-rate-settings` dari `main` (sudah termasuk `v0.14.7` +
`v0.14.5.1`). Melanjutkan cluster Commission yang di-pause sejak `v0.9.2`. **Belum di-merge/tag** —
menunggu verifikasi manual Agung. **DB dev sudah lebih maju dari `main`**: `php artisan migrate` +
`db:seed --class=RolesAndPermissionsSeeder` sudah dijalankan ke DB dev (agar bisa diverifikasi lewat HTTP
nyata). Kalau branch ini akhirnya dibatalkan: `php artisan migrate:rollback --step=1` (drop
`commission_rates`) + hapus permission `commission_rates.view`/`.manage`. Pelajaran v0.14.5.1 (jangan
tinggalkan DB lebih maju dari kode) berlaku — closure harus segera menyusul setelah Agung OK.

### Investigasi (Langkah 0) — kondisi terkini setelah rename Agent→Referrer + cluster v0.14.x

- `commission_ledger` (v0.3.0) tidak berubah selain rename `agent_id`→`referrer_id` (v0.9.1). Kolom:
  `tenant_id`/`referrer_id`/`customer_id` (NOT NULL), `amount` (nullable), `status` (default `pending`,
  cast `CommissionStatus`: Pending/Eligible/Approved/Paid/Rejected), `notes`. **0 baris.** Tidak ada
  referensi paket sama sekali.
- `referrers.commission_rate` sudah di-DROP di v0.9.2 (migration-nya sendiri bilang "superseded by a
  per-package rate table planned for v0.9.3"). `ReferrerType`: Sales/Teknisi/Freelance/Admin. **0 baris
  referrer**, jadi 0 akun login.
- `PppPackage` pricing: `cost_price`/`sell_price` (default 0), `promo_price` (nullable), `tax_percent`.
  **1 baris, soft-deleted** (`test-10Mbps-HomeFixed-1`, sisa test v0.14.5) → efektif 0 paket aktif.
- `RegistrationService::register()`: komentar lama "amount diisi nanti di sprint Commission" **sudah
  hilang** — sekarang `CommissionLedger::create()` cukup omit `amount` (→ null). `register()` tidak punya
  parameter paket; `customers.package` string bebas. Test `RegistrationServiceTest` meng-assert eksplisit
  `'amount' => null` — akan perlu diubah saat v0.9.4 mulai mengisi amount.
- RBAC: v0.9.2 rename `super_admin`→`superadmin` + role baru `administrator` (`ADMIN_TIER_ROLES`,
  `giveToAdminTier()`). `finance` role terdefinisi tapi 0 permission.

### Implementasi

- **Migration `commission_rates`**: `tenant_id`, `ppp_package_id` (FK, NOT NULL, unik parsial
  `WHERE deleted_at IS NULL`), `recurring_amount`/`limited_count_amount`/`titip_amount`
  (`decimal(12,2)` nullable), `limited_count_times` (`unsignedInteger` nullable), `is_active`
  (default true), `timestamps` + `softDeletes`.
- **`App\Models\CommissionRate`** — `belongsTo(PppPackage)`; `PppPackage::commissionRate()` (`HasOne`).
  `CommissionRate::schemeErrors()` = satu sumber aturan lintas-field (pasangan `limited_count_*`, minimal
  1 skema) dipakai bareng FormRequest + Livewire. "Terisi" = bukan null & bukan `''` (angka 0 sah).
- **RBAC**: `commission_rates.view`/`commission_rates.manage` → `giveToAdminTier()` (superadmin +
  administrator). Diverifikasi langsung di DB dev: superadmin/administrator = Y/Y; noc/finance/
  customer_service = n/n.
- **REST API** (`App\Http\Controllers\Api\V1\CommissionRateController`, `App\Services\CommissionRateService`,
  Store/UpdateCommissionRateRequest, `CommissionRatePolicy`): `GET/POST /commission-rates`,
  `GET/PUT/DELETE /commission-rates/{commission_rate}` di bawah `auth:sanctum` (tenant-level, tanpa
  `reseller.context`). `ppp_package_id` tidak bisa diubah lewat `PUT`. Lihat `docs/API.md`.
- **Livewire `/commission-rates`** (`App\Livewire\Commission\CommissionRateIndex`): me-list **SEMUA**
  PppPackage (bukan hanya yang sudah punya rate), badge "Belum diatur"/"Aktif"/"Nonaktif", form edit
  inline per paket (pola sama dengan `BandwidthProfileIndex`). Link sidebar di section "Operasional"
  tepat di bawah "Referrer".
- **Test** (28 baru): `CommissionRateApiTest` (17 — CRUD, validasi pasangan/minimal-1/non-negatif/0-sah,
  unik per paket, tenant isolation, RBAC superadmin+administrator vs customer_service, pesan error ID),
  `CommissionRateIndexLivewireTest` (11 — list semua paket, soft-deleted tidak muncul, set/edit/hapus
  rate, semua validasi, RBAC).

### Verifikasi HTTP nyata (2×, terhadap `https://boss.bajastu.id`)

- Login `super_admin@boss.local` → `/dashboard` 200, sidebar berisi `<a href=".../commission-rates">Rate
  Komisi</a>`. `GET /commission-rates` → 200, render judul + kolom + empty-state "Belum ada Profil PPP"
  (benar — 0 PppPackage aktif).
- Login `customer_service@boss.local` → sidebar TIDAK ada `commission-rates` (0 kemunculan),
  `GET /commission-rates` → **403**.

**Kondisi data**: fitur akan tampil kosong sampai ada Profil PPP sungguhan dibuat lewat `/ppp-packages` —
bukan bug, cuma kondisi data saat ini (1 PppPackage yang ada sudah soft-deleted).

## v0.14.5.1 — Revisi Pesan Error Bahasa Indonesia + Prioritas Dropdown (merged + tagged `v0.14.5.1` 2026-09-01)

**Catatan status**: branch `revisi-pesan-error-dan-prioritas` (`55b717e`), dibuat dari `main` pada
`9e2ffee` (v0.14.5). 2 revisi terpisah, berlaku lintas seluruh cluster "Profil Paket" (Bandwidth Profile,
IP Pool Pelanggan, Grup Profil, Profil Hotspot, Profil PPP), bukan cuma satu form.

**PELAJARAN — closure branch ini sempat KELEWAT selama ~1 hari, menyebabkan split state + bug 500 aktif.**
Alurnya: 2026-08-31 revisi ini selesai dikerjakan + diverifikasi manual lolos oleh Agung, dan
`php artisan migrate` dijalankan ke DB dev (2 migration `change_priority_to_integer_*` tercatat **batch
35**). Tapi git closure-nya (merge branch → `develop` → `main`, tag) tidak pernah dijalankan. Lalu kerja
v0.16.0 (Core Network Infrastructure) lanjut di atas DB yang sama (batch 36–41) dan di-tag `v0.16.0`,
disusul v0.14.7 — semua tanpa kode branch ini. Akibatnya kode di `main`/`develop` (`priority` string,
factory + `HotspotPackageIndex::save()`/`PppPackageIndex::save()` mengirim `'Default'`) berjalan melawan
kolom DB yang sudah `smallint` → **setiap create Profil Hotspot/PPP lewat UI melempar
`SQLSTATE[22P02] invalid input syntax for type smallint: "Default"` (500)**. Test suite tetap hijau karena
test jalan di SQLite (loosely typed) yang menyamarkan mismatch ini. Gap ketahuan saat menyusun laporan
penutup cluster v0.14.x ("kok tidak ada tag v0.14.5.1?"), lalu branch dicari, dikonfirmasi masih utuh
(lokal + `origin`, sinkron di `55b717e`), dan di-merge sebagai fix bug aktif — bukan closure rutin.
**Pencegahan ke depan**: kalau sebuah revisi butuh `migrate` dijalankan ke DB dev sebelum di-merge,
merge/tag-nya harus segera menyusul di sesi yang sama — jangan tinggalkan DB "lebih maju" dari kode di
`main`; kalau memang harus ditunda, catat di ROADMAP sebagai item terbuka yang eksplisit, bukan cuma
"menunggu verifikasi" di CHANGELOG.

### Revisi 1 — Pesan Validasi Bahasa Indonesia

- **Akar masalah**: `lang/id/validation.php` (translation resmi Laravel untuk pesan validasi) TIDAK
  PERNAH ada di codebase ini — hanya `lang/id.json` (translation string bebas untuk label widget) yang
  ada. Locale aplikasi sendiri sudah benar `id` (`APP_LOCALE=id` di root `.env`, dikonfirmasi live via
  `config('app.locale')`/`app()->getLocale()`), dan `App\Http\Middleware\SetLocale` sudah benar
  menyinkronkan locale dropdown UI ke `App::setLocale()` di seluruh request `web` — jadi bukan masalah
  resolusi locale, murni file translation validasi yang tidak pernah dibuat.
- **Sumber translation**: `laravel-lang/lang` (proyek open-source translation resmi Laravel, dipakai
  luas untuk 126+ bahasa termasuk Indonesia) di-require sementara sebagai `--dev`, dipakai untuk generate
  `lang/id/validation.php`/`auth.php`/`pagination.php`/`passwords.php` via `php artisan lang:add id`, lalu
  **package-nya dihapus lagi** — sudah tidak diperlukan di runtime, cuma alat generate satu kali (pola sama
  seperti `payment-gateway:import-env`, sebuah helper transisi sekali pakai). `composer.json`/`composer.lock`
  dikonfirmasi tidak ada diff sama sekali setelah dihapus. `lang/id.json` yang sudah ada juga ikut
  diperkaya proses ini (bertambah ~60 string framework-level seperti pesan halaman error/pagination/auth
  notification — tidak ada string existing yang hilang, dikonfirmasi lewat diff langsung).
- **Nama field ("attribute") juga diterjemahkan** — `lang/id/validation.php` sendiri cuma menerjemahkan
  STRUKTUR pesan, bukan nama field domain (`sell_price` tetap `sell_price` tanpa translation tambahan).
  `App\Support\ProfilPaketAttributeLabels` — satu sumber tunggal nama field bahasa Indonesia
  (`sell_price` → "Harga Jual", dst), dipakai dari 2 sisi: `forFormRequest()` (snake_case, dipanggil dari
  `attributes()` di 11 FormRequest — Store/Update × 5 modul + `UpdateExpiredProfileRequest`) dan
  `forLivewire()` (camelCase + varian `edit`-prefixed otomatis, dipanggil dari `validationAttributes()` —
  hook resmi Livewire yang berlaku untuk SEMUA `validate()` di komponen itu, termasuk `#[Validate]`
  attribute-based rules — di 5 Livewire component + `NasIndex` khusus untuk field modal Profil Expired
  yang relevan cluster ini).
- **Diverifikasi REAL end-to-end**, bukan cuma manual cek: request API sungguhan (`curl` dengan Sanctum
  token nyata) ke `POST /api/v1/ppp-packages` dengan `sell_price < cost_price` menghasilkan persis "Harga
  Jual harus bernilai lebih besar dari atau sama dengan 200." — bukan "The sell price field..."; dikonfirmasi
  juga lewat panggilan Livewire AJAX nyata (protokol yang sama persis dipakai browser) di form Profil PPP.
- **Regresi**: `ValidationMessagesInIndonesianTest` (file baru, 10 test — API + Livewire, lintas 5 modul).

### Revisi 2 — Prioritas Jadi Dropdown Queue

- **Verifikasi range dilakukan SEBELUM implementasi, langsung ke `ro-hotspot.bajastu.id` (RouterOS
  7.12.1)** — perkiraan Agung (1-7) TIDAK dipakai mentah-mentah. Temuan nyata:
  1. Range genuinely **1-8**, bukan 1-7 — dikonfirmasi dari pesan error RouterOS sendiri di
     `/queue simple`: "value of upload-priority out of range (1..8)".
  2. `/ppp profile` DAN `/ip hotspot user profile` TIDAK punya parameter `priority` berdiri sendiri
     ("unknown parameter priority", dikonfirmasi live di keduanya).
  3. Satu-satunya jalur push priority per-profil: slot ke-5 syntax `rate-limit` extended RouterOS
     (`rx-rate/tx-rate rx-burst-rate/tx-burst-rate rx-burst-threshold/tx-burst-threshold
     rx-burst-time/tx-burst-time priority`) — dikonfirmasi live genuinely diterima & tersimpan.
  4. Slot embedded ini TIDAK divalidasi RouterOS sendiri (menerima 9, di luar 1-8) — dropdown BOSS App
     jadi satu-satunya penjaga range yang nyata.
  5. Default RouterOS SENDIRI (saat priority genuinely tidak pernah di-set) adalah **8** (prioritas
     terendah) — dikonfirmasi dari readback `/queue simple` baru tanpa `priority=` sama sekali.
  6. Format burst-rate=burst-threshold=rate (menghilangkan headroom burst) dikonfirmasi live membuat hasil
     akhir fungsional identik dengan format lama yang polos — bukan perubahan perilaku berisiko, cuma
     penulisan eksplisit dari default yang sudah ada.
- **Migration**: `priority` di `hotspot_packages`/`ppp_packages` diubah dari string ke
  `unsignedTinyInteger`, default 8. 2 baris data existing (test row Agung, keduanya 'Default') dibackfill
  ke 8 — dicek langsung sebelum menulis backfill (bukan diasumsikan kosong).
- **`App\Support\RouterOsQueuePriority`** — satu sumber tunggal `MIN`/`MAX`/`DEFAULT` + `options()`
  (dropdown 1-8 dengan label "Tertinggi"/"Terendah — Default") + `toRateLimitString()` (builder syntax
  extended), dipakai bersama oleh `PushHotspotPackageToMikrotikJob`/`PushPppPackageToMikrotikJob` — field
  ini sebelumnya TIDAK PERNAH benar-benar di-push ke router sama sekali (dikonfirmasi dengan cek langsung
  kode sebelum implementasi, sesuai instruksi task).
- **Diverifikasi REAL end-to-end terhadap `ro-hotspot.bajastu.id` SAJA**: Profil PPP nyata dengan
  `priority=2` genuinely menghasilkan `rate-limit=15000k/15000k 15000k/15000k 15000k/15000k 1s/1s 2` di
  router — dibersihkan setelahnya, router kembali pristine.
- **Regresi**: dropdown-range test (1-8 saja, di luar range ditolak) + rate-limit-embedding test, di kedua
  modul (Livewire + Job), full regression suite dijalankan ulang.

**Merged + tagged 2026-09-01** — Agung sudah verifikasi manual lolos (dropdown Prioritas + pesan error
Bahasa Indonesia lewat browser). Merge dilakukan sebagai perbaikan bug aktif (lihat blok PELAJARAN di
atas), dry-run merge ke `develop` bersih 0 konflik. Verifikasi pasca-merge: `HotspotPackage`/`PppPackage`
factory + `Livewire save()` kini mengirim `priority` sebagai integer (default 8), create Profil
Hotspot/PPP berhasil tanpa error `smallint` (dikonfirmasi via tinker transaksional yang di-rollback,
bukan cuma baca kode); string `'Default'` kini ditolak DB dengan benar. Full regression suite hijau.

## v0.14.5 — Profil PPP (2026-08-31, merged + tagged `v0.14.5`)

**Catatan status**: branch `v0.14.5-profil-ppp`, dibuat dari `main` pada tag `v0.14.4.1` (dikonfirmasi
langsung lewat `git log`/`git tag`, bukan diasumsikan — branch lama dengan nama sama yang cuma pernah
dipakai investigasi Langkah 0 dihapus dan dibuat ulang karena sudah 3 commit basi di belakang `main`).
Detail teknis lengkap ada di `docs/ROADMAP.md` bagian "v0.14.5" dan `CLAUDE.md`.

- Paket bulanan PPPoE, setara Profil Hotspot (v0.14.4) tapi untuk pelanggan PPP — link Grup Profil
  (v0.14.3, wajib tipe `ppp`) + Bandwidth Profile (v0.14.1). Tabel `ppp_packages` — TIDAK ada konsep
  Unlimited/Limited/TimeBase/QuotaBase (murni milik Hotspot); `active_duration_value`/`active_duration_unit`
  (Masa Aktif) SELALU wajib diisi.
- **Setiap Profil PPP push `/ppp profile` BARU/TERPISAH ke router** — bukan numpuk ke `/ppp profile` milik
  Grup Profil induknya. `local-address`/`dns-server`/`parent-queue` diwarisi dari Grup Profil dan
  di-resolve LIVE setiap push (bukan disalin sekali); `rate-limit` dari Bandwidth Profile sendiri,
  `session-timeout` dari Masa Aktif sendiri. Lookup pakai `comment` (bukan workaround
  `mikrotik_profile_name` yang terpaksa dipakai HotspotPackage) — `/ppp profile` genuinely mendukung
  `comment`, dikonfirmasi live.
- **`RouterOsGateway::syncPppProfile()` diperluas lagi** — 2 parameter trailing baru `$rateLimit`/
  `$sessionTimeout`, dikonfirmasi live: format sama persis dengan `/ip hotspot user profile` (v0.14.4).
  `HotspotDurationUnit` (enum yang sudah ada) dipakai ulang untuk Masa Aktif Profil PPP, bukan enum baru.
  Perluasan signature ini mengharuskan pembaruan mekanis di 13 file fake `RouterOsGateway` di test suite.
- **Validasi collision nama lintas-tabel — inti risiko sub-versi ini**: nama `/ppp profile` yang
  di-generate Profil PPP wajib tidak bentrok dengan nama `/ppp profile` milik Grup Profil MANAPUN di NAS
  yang sama (keduanya berbagi namespace `/ppp profile` yang sama di router, di-scope per-NAS).
  `PppPackage::collidesWithExistingName()` — dipanggil identik dari FormRequest dan Livewire.
- **Diverifikasi REAL end-to-end terhadap `ro-hotspot.bajastu.id` SAJA** (`test-x86-bajastu` tidak
  disentuh): push genuinely membuat objek `/ppp profile` terpisah dari Grup Profil induknya, field lengkap
  benar (pool+dns+rate-limit+session-timeout); edit meng-update objek yang sama di tempat (bukan
  duplikat); delete genuinely menghapus dari router; validasi tipe Grup Profil dan validasi collision nama
  (termasuk kasus nama sama tapi NAS berbeda tetap diizinkan) dikonfirmasi via Livewire dan API; sidebar +
  halaman `/ppp-packages` dikonfirmasi ter-render lewat request HTTP sungguhan.
- **Regresi**: 36 test baru (Job/RouterOS sync, Livewire, REST API, unit model), full regression suite
  dijalankan ulang, Pint clean. Permission `ppp_packages.view`/`.manage` di-seed ulang ke database dev
  real. **Belum di-merge/tag** — menunggu verifikasi manual Agung.

## Verifikasi UI: Interface/VLAN & Expired Profile (2026-08-28, merged + tagged `v0.14.4.1`)

**Catatan status**: sama branch `revisi-grup-profil-interface-pppoe-server`. Detail teknis lengkap ada di
`CLAUDE.md` bagian "Verifikasi UI: Interface/VLAN & Expired Profile".

- **Laporan Agung**: field "Interface/VLAN" tidak ditemukan di form Grup Profil. Diinvestigasi end-to-end
  lewat request HTTP nyata (login session real via `boss-nginx`, lalu panggilan Livewire AJAX asli —
  protokol persis yang dipakai JS browser — bukan cuma baca kode) — **field-nya genuinely SUDAH ter-wire
  dan berfungsi**, dikonfirmasi lewat: create form (dropdown Interface/VLAN + input Service Name muncul
  saat Tipe=PPP, disabled+kosong sebelum NAS dipilih, aktif+terisi 8 interface real begitu NAS ro-hotspot
  dipilih), edit form (dropdown terisi data real yang sama untuk Grup Profil `#11`), dan modal "Profil
  Expired" di `/nas` (tombol + dropdown IP Pool real, keduanya genuinely ter-render).
- **Root cause paling mungkin, ditemukan lewat pengujian langsung**: field Interface/VLAN SENGAJA
  disembunyikan saat Tipe=Hotspot (desain awal — binding ini cuma relevan untuk PPP) — dikonfirmasi lewat
  edit form Grup Profil `#12` ("test-1Hp-Token", Hotspot type) yang benar-benar TIDAK menampilkan field
  ini. 2 dari 3 Grup Profil existing di NAS produksi (`ro-hotspot.bajastu.id`) bertipe Hotspot — kalau
  Agung menguji salah satu dari keduanya, absennya field ini terlihat seperti bug padahal desain yang
  benar.
- **Perbaikan genuinely dilakukan** (bukan cuma "sudah dari awal"): ditambahkan teks klarifikasi kecil
  ("Field Interface/VLAN & PPPoE Server hanya tersedia untuk Tipe = PPP.") di posisi field itu SAAT
  Tipe=Hotspot, di kedua form (create dan edit) — supaya absennya field menjelaskan dirinya sendiri,
  bukan terlihat seperti sesuatu yang belum ter-wire.
- **Dicek juga (tidak ditemukan gap)**: bundle frontend (`resources/js/app.js` vs `public/build/assets/
  *.js`) sempat terlihat berbeda mtime — dicek via `FrontendBuildTest` (regression guard yang sudah ada
  persis untuk kelas bug ini) dan TERBUKTI tidak stale, cuma efek mtime dari checkout git, bukan bug
  nyata. Sidebar/navigasi ("NAS", "Profil Paket → Grup Profil") dan permission (`nas.view`/`nas.manage`/
  `network_profile_groups.manage`) dikonfirmasi sudah lengkap — revisi ini tidak menambah permission baru
  sama sekali, jadi tidak ada risiko celah "permission belum di-seed ulang" seperti insiden-insiden
  sebelumnya di cluster ini.
- **Regresi**: 2 test baru (hint klarifikasi muncul untuk Tipe=Hotspot, di create dan edit form), full
  regression suite dijalankan ulang, Pint clean.

## Revisi Grup Profil — Interface/VLAN + PPPoE Server + Expired Profile (2026-08-27, merged + tagged `v0.14.4.1`)

**Catatan status**: branch `revisi-grup-profil-interface-pppoe-server`, dibuat dari `main` pada tag
`v0.14.4` (v0.14.4 sudah dikonfirmasi merged/tagged sebelum branch ini dibuat, dicek langsung lewat
`git log`/`git tag`, bukan diasumsikan). **Diberi nomor `v0.14.4.1`, bukan `v0.14.3.1`** — label
`v0.14.3.1` sudah lebih dulu dipakai (folded ke tag `v0.14.3`) untuk fitur lain ("Tipe Pemakaian IP Pool +
Sidebar Profil Paket"); pola patch-di-atas-tag-terakhir sama seperti `v0.9.2.1`, dikonfirmasi eksplisit
oleh Agung. Detail teknis lengkap ada di `CLAUDE.md` bagian "Revisi Grup Profil — Interface/VLAN, PPPoE
Server, Expired Profile" dan `docs/ROADMAP.md` bagian "v0.14.4.1".

- **Resolusi pertanyaan ambigu dari investigasi v0.14.5 Langkah 0**: `/ppp profile` "bare" (cuma pool/dns/
  parent-queue, tanpa rate-limit) yang sudah dipush Grup Profil sejak v0.14.3 SEKARANG dikonfirmasi
  fungsinya — itu adalah "Default Profile" yang dirujuk PPPoE Server untuk sesi RADIUS yang belum dapat
  profile spesifik. Pola nyata dari Winbox Agung: tiap tingkat bandwidth punya VLAN sendiri, PPPoE Server
  terikat ke satu interface/VLAN dan satu Default Profile.
- **`RouterOsGateway::listInterfaces(Nas $nas)`** — baca (READ-ONLY, tidak ada create/write VLAN baru)
  daftar interface fisik + VLAN dari NAS lewat `/interface print` (difilter type=ether/vlan). Dipakai
  Grup Profil untuk dropdown "Interface/VLAN", di-cache 30 detik per NAS (`Cache::remember`) supaya tidak
  query RouterOS berulang setiap render.
- **`RouterOsGateway::syncPppoeServer()`/`removePppoeServer()`** — push/hapus `/interface/pppoe-server/
  server`, lookup by comment (idempotent, sama pola seperti `/ppp profile`/`/ip pool` — dikonfirmasi
  `/interface/pppoe-server/server` MENDUKUNG `comment`, tidak seperti `/ip hotspot user profile`).
- **`network_profile_groups` kolom baru**: `interface_name`/`service_name` (nullable, hanya relevan untuk
  type=ppp — 3 baris existing perlu diedit manual oleh Agung). `PushNetworkProfileGroupToMikrotikJob`
  sekarang push `/ppp profile` DULU, baru (kalau kedua field terisi) push `/interface/pppoe-server/server`
  dengan `default-profile` = nama Grup Profil itu sendiri. Kegagalan PPPoE Server setelah `/ppp profile`
  berhasil tetap dilaporkan gagal (pesan gabungan), bukan disembunyikan sebagai sukses parsial.
  `RemoveNetworkProfileGroupFromMikrotikJob` menghapus kedua object secara konsisten.
- **`RouterOsGateway::syncPppProfile()` diperluas** — `remoteAddress` jadi nullable, tambah parameter
  `localAddress` opsional. Gotcha nyata dikonfirmasi via live test SEBELUM ship: `remote-address`/
  `local-address` MENOLAK string kosong (beda dari `dns-server`/`parent-queue` yang menerima kosong/
  'none') — diperbaiki dengan conditional-include hanya saat non-null, di cabang ADD maupun SET.
- **Fitur baru: "Profil Pelanggan Expired" per NAS** (`nas.expired_ip_pool_id` + kolom sync status
  sendiri) — modal kecil di halaman `/nas` ("Profil Expired"), pilih IP Pool NAS tsb, sistem push
  `/ppp profile` khusus (nama `expired-nas-{id}`) memakai pool itu sebagai `local-address`, TANPA
  rate-limit, `remote-address` kosong — persis pola Agung. `NasService::updateExpiredIpPool()` +
  `PushExpiredProfileToMikrotikJob`/`RemoveExpiredProfileFromMikrotikJob` (async, retry/backoff sama
  seperti push Grup Profil lainnya).
- **Bug nyata ditemukan tes sendiri, bukan review**: kolom `expired_profile_mikrotik_*` sempat TIDAK ada
  di `Nas::$fillable` (komentar awal salah menafsirkan konvensi `NetworkProfileGroup`) — `update()` di
  dalam `markExpiredProfileSync*()` diam-diam no-op tanpa error. Ketahuan langsung dari
  `ExpiredProfileMikrotikSyncTest`, diperbaiki sebelum sempat dipakai nyata.
- **Diverifikasi REAL end-to-end terhadap `ro-hotspot.bajastu.id` SAJA** (`test-x86-bajastu` tidak
  disentuh sama sekali): `listInterfaces()` mengembalikan 8 interface asli (5 ether + 3 VLAN); PPPoE
  Server test (`interface=vlan69-MNG`, VLAN aman non-produksi) genuinely muncul di `/interface/pppoe-
  server/server` dengan `default-profile` benar, lalu bersih terhapus; kedua entry PPPoE Server produksi
  asli (`PPPoE-Vlan110-10Mbps`/`PPPoE-REMOTE`) dikonfirmasi tidak tersentuh sepanjang proses; Profil
  Expired genuinely muncul di `/ppp profile` (`local-address=Hotspot-10Mbps`, `remote-address`/
  `rate-limit` kosong), lalu bersih terhapus setelah `expired_ip_pool_id` di-clear — router kembali ke
  state pristine (5 `/ppp profile`) di akhir setiap pengujian.
- **Regresi**: test baru ditambahkan di `NetworkProfileGroupMikrotikSyncTest` (9 kasus PPPoE Server push/
  skip/gagal/remove), `NetworkProfileGroupIndexLivewireTest` (6 kasus interface dropdown/cache/create/
  edit), `ExpiredProfileMikrotikSyncTest` (file baru, 5 kasus), `NasIndexLivewireTest` (4 kasus modal
  Profil Expired) — full suite dijalankan ulang, Pint clean di semua file yang disentuh. **BELUM
  di-merge/tag** — menunggu verifikasi manual Agung.

## v0.14.4 amendment ketiga — Field NAS + Tombol Simpan, 3 Form (2026-08-27, merged + tagged `v0.14.4`)

**Catatan status**: sama branch `v0.14.4-profil-hotspot`. Detail teknis lengkap ada di `CLAUDE.md` bagian
"Field NAS + Tombol Simpan — Investigasi 3 Form (v0.14.4 amendment ketiga)".

- **Investigasi (Langkah 0), bukan asumsi**: laporan Agung "NAS nya harus di atas Simpan biar gak salah
  save" di 3 form (IP Pool Pelanggan, Grup Profil, Profil Hotspot). Dikonfirmasi lewat pembacaan kode
  langsung: TIDAK ADA race condition (field dependent selalu reset sinkron dalam request yang sama,
  ditambah validasi cross-field yang sudah ada sejak v0.14.3 sebagai jaring pengaman kedua), dan urutan
  visual NAS/Grup Profil SUDAH menjadi field paling atas di keenam varian form (create+edit × 3 modul).
- **Yang genuinely hilang**: tombol Simpan tidak pernah disabled berdasarkan status pilihan NAS/Grup
  Profil — user baru tahu ada masalah SETELAH klik. Kemungkinan besar ini akar keluhan sebenarnya.
- **Fix**: tombol Simpan disabled (abu-abu) sampai NAS/Grup Profil dipilih, di ketiga form. Validasi
  backend 'required' dikonfirmasi SUDAH ADA sejak awal (tidak perlu kode baru) — hanya ditambah test
  eksplisit. Kolom `nas_id`/`network_profile_group_id` dikonfirmasi sudah NOT NULL di database real
  (bukan cuma file migration) — tidak perlu migration tambahan.
- **Regresi**: 9 test baru (3×disabled-button, 3×reject-via-Livewire, 3×reject-via-API), full suite
  dijalankan ulang, Pint clean.

## v0.14.4 amendment kedua — Fix Address Pool + session-timeout (2026-08-27, merged + tagged `v0.14.4`)

**Catatan status**: sama branch `v0.14.4-profil-hotspot`. Detail teknis lengkap ada di `CLAUDE.md` bagian
"Profil Hotspot — Address Pool Tidak Ter-set + Fix session-timeout (v0.14.4 amendment kedua)".

- **Koreksi temuan lama**: klaim "`/ip hotspot user profile` tidak punya field address-pool" (v0.14.3/
  v0.14.4) TERBUKTI KELIRU — dikonfirmasi via live SET test langsung ke `ro-hotspot.bajastu.id`, field ini
  nyata dan bisa di-set. Kekeliruan lama berasal dari salah baca "field tidak muncul di print" sebagai
  "field tidak ada", padahal itu cuma berarti belum pernah di-set (gotcha RouterOS yang sudah
  didokumentasikan berkali-kali di codebase ini untuk objek lain).
- **Root cause pesan error "invalid time value for argument session-timeout"**: bug di cabang SET
  `RouterOsApiGateway::syncHotspotUserProfile()` yang selalu mengirim `session-timeout='none'`/`''` saat
  nilainya null — RouterOS menolak KEDUANYA. Diperbaiki dengan pola sama seperti cabang ADD: sertakan
  field opsional hanya kalau non-null.
- `PushHotspotPackageToMikrotikJob` sekarang menyertakan `address-pool`, diambil dari IP Pool yang
  terhubung lewat Grup Profil.
- `boss-worker` di-restart 2x (setelah masing-masing fix) — dikonfirmasi memang diperlukan, container
  sempat menjalankan kode lama dari sebelum commit amandemen kuota selesai.
- **Diverifikasi REAL end-to-end terhadap `ro-hotspot.bajastu.id`**: "TOKEN-1Hp" (baris nyata Agung)
  di-resync ulang lewat jalur "Sync Ulang" asli — address-pool benar, status Tersinkron, tidak ada
  duplikat objek. Paket baru dari nol langsung Tersinkron di percobaan pertama. Kegagalan koneksi sengaja
  (kloning in-memory Nas, kredensial asli tidak disentuh) menghasilkan pesan error nyata, bukan macet
  diam-diam. `test-x86-bajastu` tidak disentuh sama sekali.
- **Regresi**: 66 test HotspotPackage-related dijalankan ulang (semua hijau), full suite dijalankan ulang,
  Pint clean.

## v0.14.4 amendment — Field Kuota untuk QuotaBase (2026-08-30, merged + tagged `v0.14.4`)

**Catatan status**: sama branch `v0.14.4-profil-hotspot`. Detail teknis lengkap ada di `CLAUDE.md` bagian
"Profil Hotspot — Field Kuota untuk QuotaBase (v0.14.4 amendment)".

- **Gap yang sudah diflag sendiri kemarin** dikonfirmasi nyata lewat screenshot Agung: form Profil Hotspot
  belum punya field "Kuota"/"Satuan Data" untuk paket QuotaBase.
- **Langkah 0**: dikonfirmasi ulang secara empiris terhadap `ro-hotspot.bajastu.id` (bukan
  `test-x86-bajastu`) bahwa kuota hanya bisa di-enforce per-USER (`/ip hotspot user`'s
  `limit-bytes-total`), tidak pernah di level profil/template — sesuai kesimpulan Langkah 0 sprint
  sebelumnya. Field DB + UI ditambahkan, push ke router **tidak** diimplementasikan (menunggu fitur
  voucher generation nanti).
- Kolom baru `quota_value`/`quota_unit`, validasi wajib-kalau-QuotaBase + terlarang-kalau-bukan
  (`required_if`+`prohibited_unless`), konsisten di FormRequest dan Livewire.
- **2 bug nyata ditemukan sendiri lewat test suite** (bukan verifikasi manual): default properti Livewire
  yang salah membuat validasi `prohibited_unless` gagal sendiri saat field belum disentuh user — kelas bug
  yang sama dengan `activeDurationUnit` kemarin, kali ini arah sebaliknya. Diperbaiki di source, bukan
  di-workaround.
- **Regresi**: 9 test Livewire baru (reaktivitas field, validasi, reset saat berpindah) + 6 test API baru
  (create/update, wajib, terlarang), full suite dijalankan ulang, Pint clean.

## v0.14.4 — Profil Hotspot (2026-08-27, merged + tagged `v0.14.4`)

**Catatan status**: branch `v0.14.4-profil-hotspot` (dari `main`, sudah include v0.14.3). Detail teknis
lengkap ada di `CLAUDE.md` bagian "Profil Hotspot (v0.14.4)".

Tabel `hotspot_packages` — katalog paket voucher/token hotspot yang bisa dijual (harga modal/jual/promo,
PPN, skema Unlimited/Limited dengan TimeBase/QuotaBase, masa aktif, shared users, prioritas, periode login),
terikat ke Grup Profil (v0.14.3, WAJIB tipe Hotspot) dan Bandwidth Profile (v0.14.1). Dibangun sebagai
entity berdiri sendiri, TIDAK terhubung ke `reseller_package_pricing` (v0.3.2) — `docs/ROADMAP.md` sendiri
sudah menunjuk Profil PPP (v0.14.5), bukan Profil Hotspot, sebagai pengganti tabel itu nantinya.

- **Investigasi Langkah 0 sebelum coding**: dikonfirmasi `reseller_package_pricing` genuinely 0 baris data
  meski secara kode masih terhubung ke `Subscription` — aman dibangun terpisah. Field real
  `/ip hotspot user profile` dikonfirmasi LANGSUNG ke router asli (`ro-hotspot.bajastu.id`, bukan
  `test-x86-bajastu`): `rate-limit`/`session-timeout`/`shared-users` semua benar-benar ada dan bisa
  di-set — tapi `comment` TERNYATA DITOLAK router untuk objek ini (beda dari `/ppp profile`/`/ip pool`),
  jadi ditambah kolom baru `mikrotik_profile_name` (di luar spesifikasi migration awal) supaya rename di
  BOSS App tidak bikin objek duplikat/orphan di router.
- **`quota_base` belum punya kolom jumlah kuota** (spesifikasi awal cuma minta flag klasifikasi) — tidak
  diciptakan sepihak, dilaporkan sebagai gap nyata untuk fitur voucher generation nanti. `priority` dan
  `login_days`/`login_start_time`/`login_end_time` tersimpan di `boss_db` tapi belum di-push ke router sama
  sekali sub-versi ini (tidak ada field RouterOS yang cocok, dikonfirmasi lewat pengujian langsung).
- **2 bug nyata ditemukan saat verifikasi manual**: class Livewire baru butuh `composer dump-autoload`
  dulu sebelum route-nya bisa dipakai (autoloader ter-optimasi); dan kesalahan setup pengujian sendiri
  (bandwidth profile yang dipakai ternyata sudah soft-deleted dari sesi testing v0.14.1 sebelumnya).
- **Diverifikasi REAL end-to-end terhadap `ro-hotspot.bajastu.id`**: jalur penolakan precondition (NAS
  belum punya Hotspot Server) dikonfirmasi nyata lewat seluruh stack asli (Service → Job → queue Redis
  asli → `boss-worker` asli → koneksi router asli), langsung `Failed` bukan retry 3x. Jalur sukses push
  objek baru TIDAK bisa diuji penuh karena `ro-hotspot` masih belum punya Hotspot Server sungguhan (bukan
  keputusan BOSS App untuk membuatkannya) — tapi bentuk perintah RouterOS yang sama persis sudah
  diverifikasi nyata terpisah lewat pengujian manual raw add/read/remove. `test-x86-bajastu` tidak
  disentuh sama sekali.
- **Regresi**: test baru mencakup Mikrotik sync (push/remove/precondition/rename), API (CRUD + validasi
  tipe Grup Profil + harga + durasi), Livewire (form + dropdown filter), unit model
  (`routerOsSessionTimeout()`), dan sidebar. Pint clean.

## v0.14.3.1 — Tipe Pemakaian IP Pool + Sidebar "Profil Paket" (implementasi selesai 2026-08-27, belum di-merge/tag)

**Catatan status**: sama branch `v0.14.3-grup-profil`, digabung sebelum closure sprint itu (bukan branch/tag
terpisah). Detail teknis lengkap ada di `CLAUDE.md` bagian "Tipe Pemakaian IP Pool + Sidebar 'Profil
Paket' (v0.14.3.1)".

- **Bagian A**: kolom baru `customer_ip_pools.usage_type` (Ppp/Hotspot/General, default `general` untuk
  baris existing — tidak ditebak dari nama). Bug nyata ditemukan Agung: form Grup Profil bisa memilih pool
  yang jelas untuk tipe lain (mis. "Hotspot-10Mbps" muncul saat Tipe=PPP). Dropdown Grup Profil sekarang
  filter reaktif berdasarkan NAS DAN Tipe (`General` selalu ikut muncul di keduanya), plus validasi backend
  independen (`StoreNetworkProfileGroupRequest`/`UpdateNetworkProfileGroupRequest`) yang menolak kombinasi
  tidak cocok meski request dikirim langsung ke API, bukan cuma andalkan filter frontend.
- **Bagian B**: Bandwidth Profile/IP Pool Pelanggan/Grup Profil (3 item flat terpisah di sidebar)
  dikelompokkan jadi 1 menu collapsible "Profil Paket" — replikasi persis pola `'children'` yang sudah
  dipakai NAS/Perangkat CPE. Murni reorganisasi visual, tidak ada route yang berubah.
- **Regresi**: 16 test baru (kompatibilitas usage_type, API + Livewire) + 4 test baru (sidebar), Pint
  clean.
- **Belum di-merge/tag** — menunggu verifikasi manual Agung (screenshot sidebar baru + konfirmasi filter
  IP Pool bekerja di browser sungguhan).

## v0.14.3 — Grup Profil (2026-08-28, merged + tagged `v0.14.3`)

**Catatan status**: branch `v0.14.3-grup-profil` (dari `main` yang sudah include v0.14.2) — kelanjutan
cluster "Profil Paket" — implementasi dan regresi selesai, menunggu verifikasi manual Agung lewat browser.
Detail teknis lengkap ada di `docs/ROADMAP.md` bagian "v0.14.3" dan `CLAUDE.md`.

Tabel `network_profile_groups` — template profil RADIUS/Mikrotik per-NAS (tipe Hotspot/PPP), terikat ke
`CustomerIpPool` dari NAS yang sama. Dipakai mulai v0.14.4/v0.14.5 sebagai referensi.

- **2 temuan arsitektural nyata dari investigasi, keduanya diputuskan eksplisit oleh Agung**: (1)
  `/ip hotspot user profile` tidak punya field pool/dns/queue sama sekali (dikonfirmasi ke router asli) —
  tipe Hotspot live-push justru update `address-pool` server `/ip hotspot` yang SUDAH ADA, menolak dengan
  pesan jelas kalau NAS belum punya Hotspot Server; (2) mulai menulis ke `radgroupreply` (sebelumnya 0
  baris/0 referensi kode di codebase ini) untuk PPP dan Hotspot, dengan atribut RADIUS standar yang sesuai.
- **2 bug nyata ditemukan lewat verifikasi manual, bukan test unit**: kolom `radgroupreply` ternyata
  lowercase di Postgres (`groupname` bukan `GroupName`) meski DDL `schema.sql` menulis mixed-case; dan
  `restrictOnDelete()` CustomerIpPool cuma memblokir hard-delete, jadi pool bisa soft-deleted independen
  dan bikin grup profil yang mereferensikannya crash — diperbaiki di 4 tempat (FormRequest + Livewire +
  Service, defense-in-depth).
- **Diverifikasi REAL end-to-end terhadap `ro-hotspot.bajastu.id`**: push/edit/delete PPP profile nyata,
  precondition Hotspot (gagal langsung, bukan retry 3x) juga dikonfirmasi nyata. `test-x86-bajastu` tidak
  disentuh.
- **Regresi**: full suite hijau (45 test baru khusus Grup Profil), Pint clean.

## v0.14.2.2 — Auto-Refresh Status Sync Router (implementasi selesai 2026-08-27, belum di-merge/tag)

**Catatan status**: masih di branch `v0.14.2-customer-ip-pool` — perbaikan bug UX ditemukan Agung: status
"Sync Router" tetap tampil "Pending" sampai reload manual browser, padahal job async sudah selesai di
belakang layar. Detail teknis lengkap ada di `docs/ROADMAP.md` bagian "v0.14.2.2".

- **`wire:poll.5s="$refresh"` kondisional** — hanya ada di HTML kalau ada baris `Pending` di halaman yang
  sedang ditampilkan (`hasPendingSync`, dihitung di `render()` dari `$pools` yang sudah di-fetch, bukan
  query terpisah). Begitu semua baris sudah `Synced`/`Gagal`, atribut `wire:poll` hilang dari render
  berikutnya dan mekanisme polling Livewire berhenti sendiri — bukan interval tetap selamanya, bukan
  matikan manual.
- **Tombol "Muat Ulang"** — `wire:click="$refresh"`, AJAX Livewire biasa, tidak ada navigasi
  halaman/reload penuh.
- **Tidak ada `wire:loading` yang perlu dikecualikan** — dikonfirmasi lewat grep dulu, komponen ini
  memang belum punya indikator loading sama sekali, jadi tidak ada yang perlu di-flicker-proof.
- Diverifikasi nyata lewat HTTPS live server (bukan cuma test suite): `wire:poll.5s="$refresh"` genuinely
  muncul di HTML asli saat baris `Pending` (dipaksa lewat `tinker` untuk memisahkan pengujian logic render
  dari timing job asli yang sudah dikonfirmasi cepat, ~1 detik, di sprint sebelumnya), dan genuinely hilang
  lagi setelah `Synced`.
- **Temuan tak terduga selama verifikasi**: pool nyata "Parent-10Mbps" milik Agung ternyata sudah
  soft-delete (di-hapus lewat UI asli, bukan oleh sesi ini) saat verifikasi berlangsung — konsisten dengan
  Agung menguji tombol "Hapus" sendiri secara paralel, dan entry-nya di router juga genuinely hilang
  (bukti tambahan pipeline delete async bekerja benar di pemakaian nyata independen). Tidak dipulihkan
  sepihak — dilaporkan apa adanya.
- **Regresi**: 5 test baru (kondisional poll present/absent, poll berhenti begitu resolve, tombol Muat
  Ulang, refresh mengambil data terbaru), Pint clean.

## v0.14.2.1 — RouterOS Live-Push, dimulai dari IP Pool (implementasi selesai 2026-08-27, belum di-merge/tag)

**Catatan status**: masih di branch `v0.14.2-customer-ip-pool` — **dimajukan dari rencana semula v0.14.6**,
kemampuan live-push RouterOS API pertama di codebase ini, sengaja dimulai khusus dari `CustomerIpPool`
(entity paling sederhana), bukan mesin generik untuk semua entity Profil Paket sekaligus. Detail teknis
lengkap ada di `docs/ROADMAP.md` bagian "v0.14.2.1" dan `CLAUDE.md`.

- **2 method baru di `RouterOsGateway`**: `syncIpPool()`/`removeIpPool()` — `/ip pool add/set/remove`,
  lookup via `comment` stabil (`"BOSS App - Customer IP Pool #{id}"`), bukan `name` (rename di BOSS App
  tetap update object yang sama di router, bukan bikin duplikat).
- **3 kolom baru** `customer_ip_pools.mikrotik_sync_status`/`mikrotik_synced_at`/`mikrotik_sync_error` —
  push selalu ASYNC lewat `PushCustomerIpPoolToMikrotikJob`/`RemoveCustomerIpPoolFromMikrotikJob` (queue),
  tidak pernah blocking request HTTP. Retry 3x, backoff 30s/2min/5min (jadwal sama dengan
  `SendWhatsappMessageJob`).
- **UI**: badge status Pending/Tersinkron/Gagal + tombol "Sync Ulang" (khusus baris Gagal) di
  `/customer-ip-pools`. `POST /customer-ip-pools/{id}/resync` di REST API.
- **Bug nyata ditemukan sebelum sempat dijalankan**: docblock berisi `range_*/mikrotikComment()` — literal
  `*/` di tengah kalimat menutup komentar PHP secara prematur, `php -l` menangkap parse error sebelum kode
  ini sempat di-deploy. Kelas bug sama dengan yang sudah didokumentasikan sebelumnya soal komentar `.rsc`
  Mikrotik, kali ini versi docblock PHP.
- **Diverifikasi REAL end-to-end terhadap `ro-hotspot.bajastu.id` (NAS uji coba yang aman) — semua 4 langkah
  benar-benar dieksekusi**: push pool nyata "Parent-10Mbps" milik Agung (muncul di router), edit (ter-update
  di tempat), simulasi kegagalan nyata + retry otomatis berhasil sendiri, dan delete (pakai pool throwaway
  terpisah supaya tidak menghapus data Agung). `test-x86-bajastu` tidak disentuh sama sekali.
- **Regresi**: full suite 920/920 hijau (14 test baru), Pint clean.

## v0.14.2 — IP Pool Pelanggan (implementasi selesai 2026-08-27, belum di-merge/tag)

**Catatan status**: branch `v0.14.2-customer-ip-pool` (dari `main` yang sudah include v0.14.1) — kelanjutan
cluster "Profil Paket" — implementasi dan regresi selesai, menunggu verifikasi manual Agung lewat browser
sebelum merge/tag. Detail teknis lengkap ada di `docs/ROADMAP.md` bagian "v0.14.2" dan `docs/API.md`.

Tabel `customer_ip_pools` — IP range yang dialokasikan ke perangkat/end-device PELANGGAN (hotspot/PPP) di
sebuah NAS, **genuinely berbeda dari `VpnIpPool`** (v0.6.2, tunnel IP pool antara NAS dan BOSS App sendiri)
— dikonfirmasi lewat investigasi grep ulang sebelum model dibuat, tidak ada konsep lain yang bentrok.

- **`nas_id` wajib (NOT NULL, `restrictOnDelete()`)** — sebuah IP pool pelanggan tidak masuk akal tanpa NAS
  fisik yang menaunginya; menghapus NAS yang masih punya pool harus jadi tindakan eksplisit.
- **Unique `(nas_id, name)`, bukan `(tenant_id, name)`** — dua NAS berbeda boleh masing-masing punya pool
  bernama sama; satu NAS tidak boleh punya dua pool aktif nama sama. Partial index (`WHERE deleted_at IS
  NULL`), sama pola dengan `bandwidth_profiles`.
- **Validasi 3 lapis**: IP valid + `range_end >= range_start`; gateway/range harus di dalam
  `network_address` (network..broadcast inklusif, sengaja lebih longgar dari `CidrRange`'s "usable host"
  yang VPN-tunnel-specific); overlap range antar pool **di NAS yang sama** ditolak (`CustomerIpPool::
  overlapsRange()` + `CustomerIpPoolService::overlapsExistingRange()`), range identik di NAS berbeda tetap
  diizinkan.
- **Gotcha nyata ditemukan dari pattern factory existing (bukan kode baru)**: `OltDeviceFactory` (v0.8.1)
  menaruh closure `tenant_id` SEBELUM `nas_id` di `definition()` — dikonfirmasi langsung menyebabkan bare
  `OltDevice::factory()->create()` (tanpa override `nas_id`) ERROR nyata, karena Laravel resolve attribute
  closure sesuai URUTAN array, bukan nama. `CustomerIpPoolFactory` dibuat dengan urutan yang benar (`nas_id`
  sebelum `tenant_id`, meniru `CustomerContactFactory` yang sudah benar) — tidak menyentuh bug existing di
  `OltDeviceFactory`, di luar scope, hanya dicatat.
- **Governance/sidebar checklist**: link sidebar ditambahkan tepat setelah Bandwidth Profile di section
  "Network" — per instruksi eksplisit supaya tidak mengulang insiden v0.14.1.
- **Regresi**: full suite 906/906 hijau (32 test baru khusus CustomerIpPool: 19 API + 13 Livewire), Pint
  clean (2 isu style ditemukan & diperbaiki otomatis).

## v0.14.1 — Bandwidth Profile (2026-08-27, merged + tagged)

**Catatan status**: branch `v0.14.1-bandwidth-profile` (dari `main` yang sudah include v0.9.2), sprint
pertama cluster baru "Profil Paket" (v0.14.0, 7 sub-versi, terinspirasi MixRadius V3.2) — implementasi dan
regresi selesai, sudah diverifikasi manual Agung lewat browser, merge `--no-ff` ke `develop` lalu `main`,
tag `v0.14.1` dibuat, di-push ke GitHub.

Fondasi cluster: tabel `bandwidth_profiles` (profil reusable upload/download min-max, disimpan Kbps
secara internal). Detail teknis lengkap ada di `docs/ROADMAP.md` bagian "v0.14.1" dan `CLAUDE.md`.

- **Konversi satuan Kbps/Mbps di layer form** — pemilih satuan di Livewire murni kenyamanan input, REST
  API selalu Kbps.
- **Partial unique index** `(tenant_id, name) WHERE deleted_at IS NULL` — nama yang sudah di-soft-delete
  bisa dipakai ulang.
- **2 bug nyata ditemukan & diperbaiki lewat test suite**: `formatKbps()`'s `rtrim($str, '0')` memakan
  digit signifikan bilangan bulat (`50000 Kbps` sempat tampil `"5 Mbps"`, bukan `"50 Mbps"`) — dihapus,
  tidak diperlukan sama sekali karena PHP tidak pernah menghasilkan padding trailing-zero; `Rule::unique()`
  tidak otomatis exclude baris soft-deleted (beda dari query Eloquent biasa) — diperbaiki dengan
  `->whereNull('deleted_at')` eksplisit di 4 tempat.
- **Governance note permanen ditambahkan ke CLAUDE.md**: NAS `test-x86-bajastu` adalah PRODUCTION (bukan
  environment uji coba meski namanya mengandung "test"), `ro-hotspot.bajastu.id` yang aman untuk testing —
  berlaku untuk seluruh cluster v0.14.x, krusial mulai v0.14.6 (RouterOS live-push).
- **Bug nyata ke-3, ditemukan lewat testing manual UI Agung**: 2 baris "10Mbps" aktif berdampingan lolos
  validasi unique — root cause BUKAN regresi fix soft-delete (dikonfirmasi lewat hex dump kolom `name`:
  satu baris punya trailing space `"10Mbps "`, byte-berbeda dari `"10Mbps"`, jadi memang bukan nilai yang
  sama di level DB). Akar masalah: tidak ada `trim()` di jalur create/update manapun. Diperbaiki dengan
  `trim()` di 5 titik (defense-in-depth): mutator `BandwidthProfile::name()`, `prepareForValidation()` di
  kedua FormRequest (Store/Update), dan trim eksplisit di `BandwidthProfileIndex`'s `createProfile()`/
  `updateProfile()` (validasi inline Livewire tidak lewat hook `prepareForValidation()`). Data dev
  dibersihkan (baris `id=3` yang terkontaminasi trailing space di-soft-delete, `id=4` yang bersih
  dipertahankan — menyimpang dari default "pertahankan yang tertua" karena baris tertua justru yang
  terkontaminasi). Diverifikasi ulang nyata lewat HTTPS live server (`curl` + Bearer token asli): duplikat
  dengan/tanpa trailing space sama-sama `422` setelah fix.
- **Konfirmasi eksplisit diminta & dibuktikan**: `trim()` di kelima titik hanya memotong spasi
  depan/belakang, TIDAK menyentuh spasi di tengah kata (`"15 Mbps"` tetap tersimpan `"15 Mbps"`, bukan
  `"15Mbps"`) — sudah benar sejak awal (semua 5 titik pakai `trim()` polos PHP, tidak ada
  `preg_replace`/`str_replace`), dibuktikan lewat 4 test baru, tidak ada perubahan kode yang diperlukan.
  Diverifikasi manual UI oleh Agung — LOLOS.
- **Regresi final**: 873/873 test hijau (26 test khusus BandwidthProfile: 17 awal + 5 fix duplikat + 4
  konfirmasi whitespace-tengah), Pint clean di semua file yang disentuh.

## v0.9.2.1 — Hotfix: Konflik AllowedIPs WireGuard, OLT LibreNMS down 2 hari (2026-08-27, merged + tagged)

**Tag patch `v0.9.2.1`** (4-segmen di atas `v0.9.2`, sama pola dengan tag `v0.3.0.1`) — bukan sprint fitur
baru bernomor, murni perbaikan insiden produksi mendesak, di luar alur sprint v0.14.x biasa (dari `main`,
terpisah dari branch `v0.14.1-bandwidth-profile` yang di-stash sementara selama fix ini dikerjakan). Detail
teknis lengkap ada di `CLAUDE.md` bagian "OLT AllowedIPs Conflict — Real Incident & Fix".

Root cause: `services.vpn.olt_management_subnet` (`10.168.100.0/24`) di-assign unconditional ke SEMUA akun
WireGuard NAS sejak v0.8.1 — WireGuard cuma izinkan 1 peer klaim 1 CIDR per interface, jadi NAS `ro-hotspot`
(tanpa OLT sama sekali) yang di-regenerate 24 Agustus mencuri klaim subnet dari NAS `test-x86-bajastu`
(yang benar-benar punya 3 OLT terdaftar) — ketiga OLT hilang dari monitoring LibreNMS selama ~2 hari
(2026-08-24 s/d 2026-08-27) tanpa terdeteksi sebagai bug WireGuard (sempat diduga firewall MikroTik).

- **Investigasi read-only dulu** — dikonfirmasi lewat log LibreNMS/dispatcher, SNMP manual, `wg show`
  lintas-3-node, timestamp RRD, dan korelasi presisi ke `vpn_accounts.created_at` sebelum satu baris kode
  pun diubah.
- **Fix**: `Nas::oltDevices()` (relasi baru) + `VpnProvisioningService::issueWireGuardCredentials()` sekarang
  cuma widen `AllowedIPs` untuk NAS yang benar-benar punya minimal 1 `OltDevice` terdaftar.
- **Eksekusi ke tunnel live dilakukan bertahap dengan safety net**: snapshot `wg show wg0 dump` disimpan
  sebagai rollback plan sebelum apa pun disentuh, fragment dikoreksi lewat mekanisme reconcile-loop
  otomatis yang sudah ada (BUKAN `wg syncconf` manual), diverifikasi tidak ada gangguan ke kedua tunnel
  (handshake tetap fresh, transfer counter tetap naik) sebelum dan sesudah.
- **Hasil**: ketiga OLT kembali `status=1` (UP) di LibreNMS dengan `last_polled` real-time, SNMP manual
  berhasil ke ketiganya, `librenms-dispatcher` berhenti melaporkan "Polling device unreachable".
- **Regresi**: 848/848 test hijau (termasuk test baru untuk skenario insiden nyata: config OLT subnet
  di-set global tapi NAS tanpa OLT harus tetap di-omit), Pint clean.

## v0.9.2 — CRUD Referrer, Portal Login & RBAC Two-Tier (2026-08-26, merged + tagged)

**Catatan status**: branch `v0.9.2-referrer-crud-portal-rbac` (dari `main` yang sudah include v0.9.1) —
implementasi dan regresi selesai, sudah diverifikasi manual Agung lewat browser, merge `--no-ff` ke
`develop` lalu `main`, tag `v0.9.2` dibuat, di-push ke GitHub.

CRUD Referrer (admin) + portal login self-service pertama untuk persona non-admin di codebase ini, plus
RBAC dua-tingkat (`superadmin`/`administrator`). Detail teknis lengkap ada di `docs/ROADMAP.md` bagian
"v0.9.2" dan `CLAUDE.md` bagian "Two-Tier Admin" + "CRUD Referrer, Portal Login & Cross-Persona Middleware".

- **RBAC**: `super_admin` di-rename in-place jadi `superadmin` (migrasi nyata, bukan cuma seeder — user
  existing otomatis ikut tanpa re-assignment), role baru `administrator` dengan permission operasional
  identik (beda cuma di kapabilitas manage role/permission masa depan, belum dibangun). Investigasi awal
  menemukan `super_admin` SUDAH berfungsi sebagai catch-all full-access role — dikonfirmasi ulang ke Agung
  sebelum lanjut (rename in-place, bukan tambah role catch-all kedua).
- **CRUD Referrer**: REST API (`ReferrerController`/`ReferrerService`) + Livewire `/referrers`. Create bisa
  sekalian generate akun login (password acak, ditampilkan SEKALI, TIDAK dikirim otomatis lewat WhatsApp)
  atau tanpa akun. Kolom `referrers.commission_rate` (deprecated) di-drop.
- **Portal login Referrer**: `/referrer/login` (HP+password, terpisah dari `/login` Fortify), guard `web`
  sama dengan admin. Portal scope minimal: profil, daftar referral, placeholder rekap komisi.
- **Middleware `admin.panel`/`referrer.portal`**: menutup celah "tidak ada yang blokir akses lintas-persona"
  yang ada sejak v0.1.0. Instruksi awal minta cek role Administrator/Superadmin hardcoded — kalau diikuti
  persis akan me-lockout 7 role staff lain dari seluruh panel admin; dikonfirmasi ulang ke Agung dan
  diperbaiki jadi cek "permission Spatie apa pun ATAU keanggotaan reseller_users aktif". Dua regresi nyata
  lagi ditemukan & diperbaiki lewat full test suite saat membangun cek ini.
**Fixed (ditemukan saat testing manual v0.9.2, digabung ke branch yang sama sebelum closure)**:
- **Root routing `/`** — masih route bawaan scaffold Laravel (`view('welcome')`), belum pernah diganti sejak
  v0.1.0. Sekarang: guest → redirect `/login`; user dengan akses admin panel (rule yang SAMA persis dengan
  `EnsureAdminPanelAccess::userHasAccess()`) → redirect `/dashboard`; Referrer murni (tanpa akses admin) →
  redirect langsung `/referrer-portal`, bukan `/dashboard` (supaya tidak kena 403 dulu baru redirect ulang).
  `welcome.blade.php` dihapus (dicek dulu tidak dipakai di tempat lain).
- **Logout UI admin panel** — route logout Fortify sudah ada & berfungsi sejak awal, tapi belum ada tombol
  di UI manapun. Ditambahkan dropdown profil (avatar inisial nama) di pojok kanan atas layout admin
  (`layouts/app.blade.php`), berisi nama user + tombol Logout.
- **Logout portal Referrer, gap nyata ditemukan saat membangun fix di atas**: route logout Fortify yang
  sudah dipakai portal Referrer sejak awal v0.9.2 SELALU redirect ke `/` (target global Fortify, tidak bisa
  dibedakan per-persona) — begitu root route baru berlaku, logout dari portal Referrer akan salah arah ke
  `/login` admin, bukan `/referrer/login`. Dibuat route logout khusus (`POST /referrer/logout`,
  `ReferrerLoginController::logout()`, mekanisme sama persis dengan Fortify tapi redirect eksplisit ke
  `/referrer/login`). Header portal juga diperbarui menampilkan nama Referrer yang login.
- **Regresi ditemukan & diperbaiki dari fix di atas sendiri**: `x-data="{ open: false }"` di dropdown profil
  baru bentrok literal dengan string yang sama dipakai test lain untuk menghitung baris di halaman CPE
  (`CpeDeviceShowPageTest`) — diganti jadi `profileMenuOpen` supaya unik. `ExampleTest` bawaan Laravel
  (`GET / harus 200`) diupdate mengikuti perilaku baru (sekarang redirect, bukan 200 statis).
- **Regresi**: 847/847 test hijau (5 test baru untuk root routing + logout), Pint clean. Diverifikasi nyata
  end-to-end lewat HTTPS dev server untuk seluruh alur: guest→login, admin login→root→dashboard,
  admin logout→root→login, referrer login→root→portal (dengan nama+tombol logout tampil benar di header),
  referrer logout→`/referrer/login`.

## v0.9.1 — Rename Agent → Referrer (2026-08-25, merged + tagged)

Rename fondasi sebelum masuk logic Commission (v0.9.0) — tabel/model `Agent` (ada sejak v0.2.0-v0.3.0,
sebenarnya merepresentasikan referral sales/internal) di-rename jadi `Referrer`, supaya nama "Agent" bebas
dipakai khusus untuk modul Token/Hotspot masa depan tanpa tabrakan. Detail teknis lengkap ada di
`docs/ROADMAP.md` bagian "v0.9.1 — Rename Agent → Referrer" dan `CLAUDE.md`.

- **Nama final "Referrer", bukan "Sales"** — dipilih setelah investigasi menemukan tabrakan nyata dengan
  `AgentType::Sales`/`RegistrationChannel::Sales`/role Spatie `sales_internal`/`sales_freelance` (semuanya
  tetap utuh, tidak disentuh).
- **Rename penuh**: model, enum (`AgentType`→`ReferrerType`, value case tetap sama), factory, seeder,
  API resource, widget dashboard (`TopAgents`→`TopReferrers`, label "Referrer Teratas"), tabel `agents`→
  `referrers` dan kolom FK terkait — semua lewat migrasi `Schema::rename()`/`renameColumn()` (data
  preserved, bukan drop+recreate).
- **Breaking API contract disengaja**: `referred_by_agent_id`→`referred_by_referrer_id` di
  `POST /api/v1/registrations` — diterima karena project masih pre-production, belum ada consumer
  eksternal.
- **Regresi**: 805/805 test hijau, Pint clean di semua file yang disentuh, re-grep "agent" case-insensitive
  setelah rename bersih (kecuali migrasi historis dan satu false positive "SNMP agent").

## v0.8.4 — Dialup Syslog & RADIUS Migration (2026-08-25)

Merge branch `v0.8.4-dialup-syslog` ke `develop` lalu `main`, tag `v0.8.4`
dibuat. Detail teknis lengkap ada di `docs/ROADMAP.md` bagian "v0.8.4 —
Dialup Syslog & RADIUS Migration" dan banyak bagian `CLAUDE.md` (lihat
daftar di ROADMAP.md). Regresi hijau di branch, `develop`, dan `main`.

Ringkasan singkat (lihat ROADMAP.md/CLAUDE.md untuk detail teknis penuh):
- **Fix infrastruktur**: SNAT per-NAS WireGuard (generalisasi block infra
  yang sebelumnya cuma setengah jadi), domain `boss.bajastu.id` + TLS
  (HTTPS pertama di proyek ini), refactor `VpnSyncRouteFragments`
  (hilangkan noise login RouterOS API per-menit, ganti file status
  shared-volume).
- **Syslog rsyslog→LibreNMS**: sidecar baru terima UDP:514 dari NAS
  MikroTik, teruskan ke LibreNMS. Empat bug nyata ditemukan & diperbaiki
  (termasuk topik RouterOS ternyata logika AND bukan OR). UI "Log" di
  Monitoring + REST API baru.
- **Migrasi RADIUS PPPoE VLAN 10**: 295 akun customer `ro-hotspot`
  dipindah dari local-secret ke `radcheck` BOSS App (`test-x86-bajastu`
  tidak disentuh sama sekali).
- **Riwayat Dialup di Detail CPE**: reaktivasi accounting SQL write
  FreeRADIUS (keputusan sadar, dikonfirmasi Agung — bukan bug fix),
  koneksi DB kedua ke `radius_db` (BOSS-009 compliant), diverifikasi
  nyata dengan data customer asli.
- **Backlog terbuka (belum dikerjakan, lihat ROADMAP.md untuk daftar
  lengkap)**: cutover penuh 295 akun (local secret `test-x86-bajastu`
  masih aktif untuk mayoritas), 3 akun pola menyimpang + 2 akun tanpa
  `customer_id` perlu penanganan manual, `Acct-Interim-Interval` belum
  dikonfigurasi, rule firewall router produksi #8 (`connection-state=
  invalid`), syslog OLT belum diriset, UX form OLT community
  auto-generate.

## v0.8.3 — Dashboard Monitoring (v0.8.2) + RX Power History, Custom Range & API (v0.8.3) (2026-08-24)

**Catatan penting**: tag ini mencakup DUA versi roadmap sekaligus, v0.8.2
DAN v0.8.3 — deviasi eksplisit dari BOSS-002, sama pola dengan v0.8.0-v0.8.1
sebelumnya. Keduanya dikerjakan berurutan dalam satu branch/sesi panjang
(`v0.8.2-monitoring-fixes`) alih-alih dua branch terpisah; splitting tag
setelah fakta tidak akan mencerminkan bagaimana kerjanya sesungguhnya
berjalan. Merge branch `v0.8.2-monitoring-fixes` ke `develop` lalu `main`,
tag `v0.8.3` dibuat di `main`. Regresi 776/776 hijau di branch, `develop`,
dan `main`. Detail teknis lengkap ada di `docs/ROADMAP.md` bagian
"v0.8.2-v0.8.3 — Dashboard Monitoring, RX Power History, Custom Range &
API" dan banyak bagian `CLAUDE.md` (lihat daftar di ROADMAP.md).

Ringkasan singkat (lihat ROADMAP.md/CLAUDE.md untuk detail teknis penuh):
- **v0.8.2 Dashboard Monitoring**: `LibreNmsService` (hybrid REST API +
  `rrdtool xport`, LibreNMS versi ini tidak punya endpoint JSON untuk
  traffic history), device list + traffic graph di `/monitoring`,
  Add/Edit/Remove device manual (mutating call pertama ke LibreNMS di
  codebase ini), self-monitoring host (SNMP) dan container
  (`docker-socket-proxy`, read-only) dikelompokkan visual VPN/LibreNMS/
  BOSS App Core/Lainnya.
- **v0.8.3 RX Power History, Custom Range, API**: riwayat RX Power
  terjadwal di Detail CPE dengan tab Custom Range (dipakai ulang di 3
  modal riwayat lewat satu trait + satu partial bersama), REST API baru
  `/api/v1/monitoring/*` + `/api/v1/cpe-devices/{id}/signal-history`
  sebagai fondasi integrasi bot WhatsApp masa depan.
- **Dua bug infrastruktur nyata ditemukan & diperbaiki**: (1) crash
  `WhatsappQueueNames` di setiap eksekusi — kemungkinan besar menghentikan
  total pengiriman WhatsApp selama bug ini ada, plus `~12GB` log yang tidak
  pernah dirotasi (`LOG_STACK` sekarang `daily`); (2) 500 nyata di Custom
  Range Device/Traffic History — dua bug bertumpuk (`diffInSeconds()`
  negatif membalik jendela `rrdtool`, plus file log root-owned
  mengeskalasi kegagalan-anggun jadi 500 mentah) — diperbaiki keduanya,
  plus loop self-healing permission `/tmp`+`storage/logs` di semua
  container PHP.
- **Backlog terbuka (belum dikerjakan)**: root cause pasti drift
  permission `/tmp` masih pertanyaan terbuka (self-healing sudah bekerja,
  tapi mekanisme drift-nya sendiri belum sepenuhnya dipastikan); riset
  syslog sudah selesai tapi belum diimplementasikan (butuh keputusan
  sensitif-produksi terpisah); audit "API wajib di semua fitur" (BOSS-006)
  masih menunggu keputusan terpisah.

## v0.8.1 — LibreNMS Monitoring, OLT Credential Registry & Dynamic Routing (2026-08-21)

Merge branch `v0.8.1-librenms-install` ke `develop` (`e47a43f`) lalu
`main` (`c321cfa`), tag `v0.8.1` dibuat. Detail teknis lengkap ada di
`docs/ROADMAP.md` bagian "v0.8.0-v0.8.1 — LibreNMS Install & OLT
Onboarding" dan `CLAUDE.md` bagian "WireGuard /30 Per-NAS Tunnel Blocks",
"OSPF Dynamic Routing", "Fragment+Reconcile Routing", "LibreNMS OLT
Onboarding".

Ringkasan singkat (lihat ROADMAP.md/CLAUDE.md untuk detail teknis penuh):
- **LibreNMS terinstall**: router `test-x86-bajastu` + 3 OLT real (ZTE
  C300, HSGQ-E04ID/CILEG, HSGQ-G02ID/BUMIREJA) ter-onboard dengan bukti
  polling data nyata (uptime, port GPON, `last_polled` aktif).
- **OLT Credential Registry** (modul baru): `olt_manufacturers`/
  `olt_models`/`olt_devices`, plus 3 addendum bug fix (konflik DataTables+
  Livewire DOM-morph, delete master data dengan referential integrity,
  toggle show/hide password).
- **Redesign addressing WireGuard**: dari satu gateway `/32` bersama
  semua NAS ke blok `/30` sticky per-NAS.
- **Routing dinamis — dua pendekatan dicoba**: OSPF (FRRouting) dibangun
  penuh dan terbukti bekerja nyata (adjacency full-mesh, bertahan lewat
  auto-switch sungguhan), lalu **dinonaktifkan secara sengaja** (kompleksitas
  operasional tidak sepadan untuk skala satu host saat ini — kode disimpan
  sebagai referensi). Digantikan **fragment+reconcile**: BOSS App menulis
  fragment rute per-NAS berdasarkan node aktif sungguhan, 5 container
  consumer baca+apply lewat polling loop sederhana.
- **Root cause ganda ditemukan untuk kegagalan SNMP OLT**: (1) firewall
  router (rule `drop connection-state=invalid`, dibuktikan lewat tes
  darurat terkontrol — firewall dimatikan sementara, langsung berhasil,
  lalu dikembalikan), dan (2) kredensial SNMP salah (community
  auto-generated vs. community asli device — dikoreksi, SNMP langsung
  jalan ke ketiga OLT).
- **Known gaps terbuka (backlog eksplisit, bukan dilupakan)**: fix
  permanen firewall rule `drop connection-state=invalid` (paling
  prioritas — firewall sudah dikembalikan ke kondisi semula, belum ada
  exception permanen untuk traffic BOSS App); UX form OLT Credential
  Registry (auto-generate community SNMP menimpa nilai yang seharusnya
  diisi manual untuk OLT lama); auto-switch flapping saat firewall
  dimatikan sementara (belum diinvestigasi); OSPF sebagai referensi masa
  depan (layak dipertimbangkan lagi hanya kalau node VPN pindah ke server
  fisik terpisah).

## v0.7.7 — GenieACS Testing Refinements (2026-08-20)

Merge branch `v0.7.x-testing-refinements` ke `develop` lalu `main`, tag
`v0.7.7` dibuat. Sesi verifikasi komprehensif yang dijanjikan di catatan
v0.7.3-v0.7.6 sebelumnya, sekaligus refinement/fitur baru yang ditemukan
perlu selama proses itu. Detail teknis lengkap dan alasan tiap keputusan
ada di `docs/ROADMAP.md` bagian "Branch v0.7.x-testing-refinements" dan
`CLAUDE.md` bagian "GenieACS Testing Refinements & Status Sync Redesign".

Ringkasan singkat (lihat ROADMAP.md/CLAUDE.md untuk detail teknis penuh):
- **Verifikasi nyata**: v0.7.3 Connection Request terbukti jalan (5/5 ZTE +
  8/8 Huawei via `nc -zv` + `informEvent="6 CONNECTION REQUEST"` di log
  genieacs-cwmp); v0.7.4 Ganti WiFi dan v0.7.5 Auto-Provisioning
  terverifikasi end-to-end ke device pelanggan asli (Natofik,
  `ZICG298E1389`), SSID diubah lalu direvert, keduanya terkonfirmasi lewat
  periodic inform sungguhan.
- **Fitur baru**: status Online/Offline dirombak dari router-ping jadi
  hybrid GenieACS `_lastInform`+`connection_request` (`RouterOsGateway::
  pingHost()` tetap ada, cuma tidak dipakai lagi di jalur ini); DataTables
  UI + halaman Detail terpisah untuk `/cpe-devices`; Remove/Ganti Modem;
  Cek Status Device (self-service diagnostic 4-tahap); auto-matching
  legacy device berkelanjutan (`cpe:auto-match-legacy-devices`); resolusi
  MAC via `pppoeMac` + multi-WAN index dinamis.
- **Data**: import penuh 561 customer MixRadius (2 gagal, NIK bentrok —
  belum diselidiki), NIK encryption, CID auto-generate.
- **Infrastruktur**: preset default GenieACS baru (auto-refresh terjadwal
  SSID/password/MAC/UpTime/Connected Hosts), DHCP Option 43 (infra,
  membawa ~70 device baru ke GenieACS), fix `MikrotikScriptGenerator`
  (`comment=` di semua baris `add`, konfirmasi format CIDR `/32` bukan bug
  kita).
- **PPPoE (`radcheck`) provisioning + API/otorisasi teknisi-bot** —
  dikonfirmasi eksplisit di luar scope v0.7.5, diberi nomor **v0.12.0**.
- **Known gaps terbuka**: 2 customer gagal import; ~20-an kombinasi vendor
  kecil belum ada RX Power; verifikasi UI browser v0.7.3-v0.7.6 belum
  pernah dicoba Agung langsung.

## v0.7.6 — GenieACS Connected Clients (dengan histori)

Sub-sprint keenam cluster v0.7.0. Baca object TR-069 `LANDevice.{i}.
Hosts.Host.{n}` (client yang terhubung ke WiFi/LAN CPE) — **histori, bukan
snapshot**: satu baris per `(cpe_device_id, mac_address)` di
`cpe_connected_hosts`, tidak pernah satu baris per poll (menghindari tabel
membengkak tak terkendali). `first_seen_at` cuma diisi sekali; `last_seen_at`
di-update tiap MAC itu masih muncul; `is_active` jadi `false` (baris
**tidak pernah dihapus**) begitu MAC yang sebelumnya tercatat tidak muncul
lagi di satu poll — `hostname`/`ip_address` hanya ditimpa kalau poll saat
itu punya nilai baru, supaya device yang sesaat melapor `HostName` kosong
tidak menghapus nama yang sudah diketahui.

- **`App\Services\Network\CpeConnectedHostsService::syncFromGenieAcs()`**
  — baca data yang **sudah tersimpan** di GenieACS, tidak pernah memicu
  `refreshObject`/Connection Request sendiri (sama posture seperti
  discovery v0.7.4/v0.7.5). Command terjadwal
  `cpe:sync-connected-hosts` (5 menit, pola sama persis `cpe:reconcile`
  v0.7.1) memanggilnya untuk tiap `CpeDevice` berstatus `online`,
  per-device best-effort — satu device gagal tidak menghentikan yang
  lain.
- **Field standar `Active`/`HostName`/`IPAddress`/`MACAddress`
  dikonfirmasi ada di DUA vendor berbeda**, bukan diasumsikan dari satu
  device saja: ZTE F663NV3.1 (5 host tercatat, `HostName` terisi di
  semuanya — tidak kosong seperti dugaan awal) DAN Huawei EG8141A5 (2
  host, plus field vendor-spesifik `X_HW_*` yang tidak ada di ZTE).
  Field-field standar itulah yang jadi basis parsing generik lintas
  vendor.
- **Nomor instance `Host.{n}` TERBUKTI tidak stabil/tidak berurutan di
  hardware nyata** — ZTE melaporkan indeks `7/10/11/67/68`, Huawei
  melaporkan `1/2`. `mac_address`, bukan `{n}`, adalah satu-satunya kunci
  identitas yang aman (sesuai unique constraint tabel ini).
- **API**: `GET /cpe-devices/{id}/connected-hosts` (+`?active_only=true`),
  read-only murni — tidak ada endpoint yang memicu sync apa pun.
- **UI Livewire**: tombol "Client" baru di `/cpe-devices`, modal tabel
  host dengan toggle "aktif saja" dan badge visual aktif/tidak aktif.
- **Temuan sampingan di luar scope sprint ini, dicatat buat sesi
  verifikasi nanti**: tree GenieACS milik Huawei EG8141A5 ternyata sudah
  jauh lebih lengkap dari investigasi v0.7.3/v0.7.4 terakhir (dulu cuma 8
  parameter, sekarang ribuan termasuk `WLANConfiguration` dan `Hosts`) —
  bisa jadi sinyal Connection Request v0.7.3 sebenarnya sudah jalan,
  belum dikonfirmasi, tidak dikejar lebih jauh sesuai arahan.
- **Test**: 13 test baru (6 service, 1 command, 4 API, 2 Livewire) — full
  regression 404 test di repo tetap hijau.

## v0.7.5 — GenieACS Auto-Provisioning (SSID/Password saja)

Sub-sprint kelima cluster v0.7.0. Scope dipersempit dari rencana awal
setelah verifikasi: **PPPoE (username/password RADIUS) TIDAK termasuk**
— kredensial itu ternyata tidak tersimpan di alur instalasi mana pun
(`work_order_devices` cuma MAC/serial, tidak ada link balik
`radcheck`→work order). Scope jadi SSID/password WiFi saja, reuse penuh
`App\Services\Network\CpeActionService` (v0.7.4).

- **Tidak ada mekanisme input teknisi sama sekali** — WhatsApp bot masih
  outbound-only (v0.4.0), Mobile App belum dibangun (v0.11.0 backlog).
  v0.7.5 karena itu terpaksa mencakup jalur input CS/admin manual
  (`PATCH /work-orders/{id}/devices/{device}/provisioning` — partial
  update sungguhan, isi SSID sekarang password menyusul tanpa saling
  menghapus — plus halaman `App\Livewire\Installation\WorkOrderShow`,
  Livewire pertama untuk modul Installation sejak v0.5.0) sebagai
  **bridge sementara**, ditandai eksplisit di kode/docs bukan UI teknisi
  final.
- **`App\Services\Network\CpeBindingService::provisionWifiIfPending()`**
  — hook baru dipanggil dari DUA titik: `bindFromWorkOrder()` (binding
  langsung online) dan `reconcilePending()` (job terjadwal berhasil
  match). `cpe_devices.wifi_provisioned_at` jadi guard anti-duplikat,
  hanya ke-set kalau push benar-benar `delivered` — **tidak ada retry
  otomatis** kalau gagal, CS perlu push manual lewat tombol "Ganti WiFi"
  v0.7.4.
- **Tidak ada actor manusia untuk aksi auto-provisioning** —
  `cpe_action_logs.performed_by` sengaja dijadikan nullable (dikonfirmasi
  Agung: lebih jujur daripada user sistem palsu), `parameters.
  triggered_by` (`auto_provisioning_binding`/`auto_provisioning_
  reconciliation`) bedain sumbernya, UI/API menampilkan **"Sistem
  (auto-provisioning)"**.
- **Test**: 17 test baru — full regression 391 test tetap hijau.

## v0.7.4 — GenieACS Remote Actions (Reboot + WiFi SSID/Password)

Sub-sprint keempat cluster v0.7.0. **Dibangun sengaja dalam mode "tidak
instan"** — keputusan sadar Agung saat planning: tidak menunggu v0.7.3
(Connection Request routing) terverifikasi dulu, karena task
queue+audit log ini tetap berguna dan benar terlepas dari itu. Setiap
aksi SELALU mencoba `connection_request` GenieACS juga (harmless kalau
gagal, gratis kalau kebetulan berhasil) — **tidak perlu perubahan kode
sama sekali** begitu v0.7.3 terbukti jalan; instant-push otomatis aktif
dengan sendirinya lewat mekanisme yang sudah ada sejak hari pertama
sprint ini (`GenieAcsClientService::sendTask()`'s default
`connectionRequest=true`).

- **`cpe_action_logs`** (tenant/reseller-scoped, pola sama persis dengan
  `whatsapp_message_logs`): `cpe_device_id`, `performed_by`,
  `action_type` (`reboot`/`set_ssid`/`set_password`), `parameters` (json,
  password TIDAK PERNAH plaintext — lihat di bawah), `genieacs_task_id`,
  `status` (`queued`/`delivered`/`failed`), `failed_reason`,
  `completed_at`.
- **`App\Services\Network\CpeActionService`** — `reboot()`/
  `setWifiCredentials()`: tulis log dulu (status `queued`) SEBELUM
  mencoba apa pun ke GenieACS, supaya jejak audit selalu ada walau
  langkah berikutnya gagal total. `status=delivered` berarti task
  berhasil masuk antrean `genieacs-nbi` (dokumen task nyata dengan
  `_id`) — **bukan** konfirmasi perangkat sudah menjalankannya, BOSS App
  tidak punya cara mengetahui itu sprint ini. `status=failed` HANYA untuk
  kegagalan enqueue itu sendiri (device belum punya `genieacs_device_id`,
  mapping parameter tidak ada, atau GenieACS menolak request) — kegagalan
  `connection_request` semata (device sedang tidak reachable) tetap
  `delivered`, sesuai constraint desain resmi.
- **`GenieAcsClientService::sendTask()`** (baru) — general-purpose task
  enqueue, selalu menyertakan `?connection_request` secara default.
  `->throw()` cuma trigger di kegagalan HTTP nyata (4xx/5xx dari
  genieacs-nbi), bukan di reason-phrase "Device is offline" yang tetap
  HTTP 202 dengan task `_id` valid (perilaku nyata GenieACS, dikonfirmasi
  manual saat investigasi v0.7.3).
- **`cpe_parameter_maps` diperluas**: `wifi_ssid`/`wifi_password` untuk
  ZTE F663NV3.1 (`oui=F86CE1`, `product_class=F663NV3a`).
  `wifi_ssid` → `WLANConfiguration.1.SSID`, **verified** (nilai nyata
  `'RUMAHVIA'` terbaca dari tree tersimpan device, `_writable=true`).
  `wifi_password` → `WLANConfiguration.1.KeyPassphrase`, path
  ada+`_writable=true` tapi **sengaja TIDAK ditandai verified** — device
  selalu mengembalikan string kosong untuk field password saat dibaca
  (perilaku keamanan CPE yang umum, bukan gap discovery), jadi tidak ada
  nilai nyata untuk dicocokkan, dan belum ada percobaan
  `setParameterValues` nyata yang dikonfirmasi berhasil.
- **Password tidak pernah plaintext di `cpe_action_logs`** — hanya
  `password_changed: true` + `new_password_fingerprint` (SHA-256, sengaja
  tanpa salt supaya tetap bisa dibandingkan "apakah sama dengan
  perubahan sebelumnya"; kredensial asli cuma pernah ada di perangkat/
  GenieACS, tabel ini bukan credential store). Kalau SSID dan password
  diubah bersamaan, keduanya dikirim sebagai SATU task GenieACS
  (`setParameterValues` dengan 2 entry), bukan dua task terpisah.
- **API**: `POST /cpe-devices/{id}/actions/reboot`,
  `POST /cpe-devices/{id}/actions/wifi` (ssid/password opsional, minimal
  satu wajib), `GET /cpe-devices/{id}/actions`. Otorisasi lewat
  `CpeDevicePolicy::manage()` (permission `cpe_devices.manage` baru, atau
  membership `reseller_users` aktif — owner ATAU staff — untuk device
  reseller sendiri, pola sama `nas`/`odps`).
- **UI Livewire** (`/cpe-devices`): tombol Reboot (`wire:confirm` — pesan
  jelas soal pelanggan terputus sebentar) dan modal Ganti WiFi
  (`wire:confirm` di tombol submit — pesan soal perangkat pelanggan
  mungkin perlu connect ulang), plus modal Riwayat Aksi. Pesan hasil
  SELALU jujur ("perintah terkirim, akan diterapkan saat perangkat
  terhubung berikutnya") — tidak pernah "berhasil reboot"/kata yang
  menyiratkan eksekusi instan sudah dikonfirmasi.
- **Test**: 24 test baru (9 `CpeActionServiceTest`, 8
  `CpeDeviceActionApiTest`, 7 tambahan di `CpeDeviceIndexLivewireTest` —
  termasuk regression test eksplisit yang mengecek pesan hasil TIDAK
  pernah mengandung kata "berhasil") — full regression 374 test di repo
  tetap hijau.

## v0.7.3 — GenieACS Connection Request Routing (implementasi selesai — verifikasi akhir PENDING)

Sub-sprint ketiga cluster v0.7.0. **Status jujur**: implementasi inti selesai
dan tiga bug infrastruktur nyata ditemukan+diperbaiki sepanjang sprint ini,
tapi **retry TCP connectivity + Connection Request end-to-end setelah
perbaikan terakhir belum pernah dijalankan ulang dan dikonfirmasi sukses**.
Agung memutuskan sadar untuk lanjut ke v0.7.4 sebelum retest terakhir ini
dilakukan — ini bukan klaim bahwa fitur ini sudah terbukti jalan.

- **Dibangun**: kolom `nas.tr069_management_subnet` (subnet manajemen TR-069
  milik NAS, mis. `10.1.0.0/20`), static boss-network IP untuk
  `genieacs-cwmp`/`genieacs-nbi` (`GENIEACS_CWMP_INTERNAL_IP`/
  `GENIEACS_NBI_INTERNAL_IP`), widen WireGuard `AllowedIPs` (server + router)
  dan firewall exception baru di `docker/wireguard/entrypoint.sh` (scoped ke
  IP genieacs-cwmp/nbi + `tr069_management_subnet`, tidak melebar ke
  `boss-network` secara umum).
- **Perbaikan `MikrotikScriptGenerator`/`VpnScriptService`** (dilakukan
  setelah tag awal dipertimbangkan, sebelum commit final sprint ini): route
  balik sekarang dihasilkan per-service (FreeRADIUS + GenieACS NBI + GenieACS
  CWMP, masing-masing `/32` + entry `allowed-address` sendiri) lewat
  `$reverseRouteTargets`, bukan hardcode satu service (FreeRADIUS) saja
  seperti cut pertama. Route subnet-utuh-lewat-tunnel (`boss-vpn-tr069-route`)
  **dihapus total** dari template — terbukti mati di router nyata karena
  connected route ke subnet lokal NAS sendiri selalu menang.
- **Tiga bug nyata ditemukan+diperbaiki sepanjang sprint ini**:
  1. **Rotasi private key WireGuard tak disadari** — "Cabut & Generate Ulang"
     menghasilkan keypair baru (bukan reuse in-place, private key WireGuard
     memang tidak pernah disimpan BOSS App), sempat disalahartikan sebagai
     "tunnel putus" padahal memang perilaku desain yang benar.
  2. **Route balik tidak lengkap** — cut pertama cuma menyertakan SATU route
     ke seluruh `tr069_management_subnet`, terbukti mati (lihat di atas).
     Diganti per-service `/32`, pola sama seperti route FreeRADIUS yang sudah
     terbukti jalan sejak v0.6.2/v0.6.3.
  3. **MASQUERADE vs `allowed-address` tidak sinkron** — ditemukan lewat
     inspeksi langsung state live container `wireguard-node3` (`wg show`,
     `iptables -t nat -L POSTROUTING`), bukan tebakan: traffic GenieACS
     NBI/CWMP di-MASQUERADE oleh `docker/wireguard/entrypoint.sh` ke IP
     tunnel node itu sendiri (reserved `.1` dari `subnet_cidr`, lihat
     `App\Support\CidrRange::gatewayAddress()`) sebelum sampai ke router —
     `allowed-address` peer WireGuard di router HARUS mencantumkan IP ini
     sebagai source yang diterima, kalau tidak paket didrop di layer kripto
     WireGuard sebelum sempat diproses RouterOS sama sekali. Parameter baru
     `$vpnNodeTunnelIp` di `wireGuardScript()` menutup celah ini.
- **Test**: 3 test baru di `MikrotikScriptGeneratorTest`, 1 test end-to-end
  baru di `VpnScriptGeneratorLivewireTest`, 2 test baru di `CidrRangeTest` —
  full regression 350 test lain di repo tetap hijau, dikonfirmasi ulang dari
  state final branch.
- **BELUM DIKONFIRMASI — jangan andalkan fitur ini di v0.7.4 sebelum ini
  diverifikasi ulang**: task `refreshObject` untuk kedua device real masih
  antre di GenieACS, retry pasca-fix #3 di atas belum pernah dijalankan:
  - Huawei EG8141A5 (`00259E-EG8141A5-48575443796B91A7`) — task
    `6a7897028f1edd3ee0656c81`
  - ZTE F663NV3a (`F86CE1-F663NV3a-ZICG296C2E7B`) — task
    `6a789984a542ad1c34df1865`

  TODO wajib sebelum v0.7.4 membangun apa pun di atas fitur ini: jalankan
  ulang `nc -zv` dari container `genieacs-nbi` ke kedua device (port 7547
  dan 58000) dan retry kedua task di atas, buktikan Connection Request
  benar-benar sampai ke device (bukan cuma tunnel WireGuard yang handshake).

## v0.7.2 — GenieACS Vendor Parameter Mapping + RX/TX Power

Sub-sprint kedua cluster v0.7.0. `cpe_parameter_maps` (platform-level, key
`oui`+`product_class`+`parameter_key`) memetakan path parameter TR-069 per
vendor/model ke nilai dunia-nyata lewat `ParameterConversionService`
(`raw`/`linear`/`sff8472_optical_log10`), diresolve untuk device nyata lewat
`CpeParameterResolverService`. CRUD API + endpoint `verify` (menandai
terverifikasi hanya lewat aksi eksplisit) + `resolve` (pembuktian
end-to-end) + UI Livewire `/cpe-parameter-maps` dengan panel "Tes Resolve"
langsung terhadap device live.

- **Formula `sff8472_optical_log10` diverifikasi nyata**, bukan cuma dari
  riset komunitas — terhadap ONT ZTE F663NV3.1
  (`F86CE1-F663NV3a-ZICG296C2E7B`) yang benar-benar connect. Empat field DDM
  optik standar SFF-8472 di object yang sama (`BiasCurrent`/`RXPower`/
  `TXPower`/`SupplyVottage`/`TransceiverTemperature`) semuanya mendarat di
  nilai dunia-nyata yang masuk akal di bawah skala yang sama — bukan cuma
  RX power sendirian yang dicocokkan. Hasil: RX -28.24 dBm, TX 2.33 dBm.
  Raw value 0 sengaja di-reject (`InvalidArgumentException`), bukan
  didiamkan jadi `-INF`.
- **Bug infrastruktur kritis ditemukan & diperbaiki**: proxy
  `boss-nginx` → `genieacs-cwmp` yang sejak v0.7.1 masih level HTTP
  (`proxy_pass` tanpa `upstream keepalive`) membuat SEMUA Digest auth gagal
  tanpa terkecuali, terlepas dari device/kredensial apa pun — GenieACS
  mengikat nonce challenge ke socket TCP tertentu, sedangkan nginx membuka
  koneksi backend baru tiap request. Ditemukan lewat investigasi penuh
  (`tcpdump` capture device nyata + rekomputasi independen algoritma MD5
  Digest-response GenieACS untuk membuktikan kredensial sudah benar sebelum
  mencari akar masalah lain). Diperbaiki dengan proxy TCP murni (`stream {}`
  module nginx, bukan `http {}`) — lihat CLAUDE.md "GenieACS Core &
  TR-069 CWMP proxying gotcha (v0.7.2)". Tanpa fix ini tidak ada satu device
  pun yang bisa sukses connect lewat auth apa pun.
- **Operasional, dibundel di commit ini**: timezone host + seluruh
  22 container diset ke `Asia/Jakarta` (host `timedatectl`,
  `TZ` env + bind-mount `/etc/localtime`/`/etc/timezone`/`zoneinfo` di
  `docker-compose.yml`) — perubahan operasional terpisah dari scope
  v0.7.2, dibundel di sini karena sudah live sejak sebelum sprint ini
  ditutup dan perlu masuk git (BOSS-001).
- **Test**: 20 test baru (formula konversi, resolver dengan `Http::fake`,
  API/policy termasuk aturan "edit definisi menurunkan status verifikasi")
  — semuanya lulus, ditambah full regression 344 test lain di repo tetap
  hijau, dikonfirmasi ulang dari state final branch (bukan angka lama).
- **Known follow-up, BUKAN bagian selesai sprint ini** (lihat
  `docs/ROADMAP.md` untuk detail lengkap): (1) backfill 400+ modem existing
  butuh kolom legacy customer ID baru di `customers` (belum ada sama
  sekali) dan strategi MAC→SerialNumber lewat GenieACS cuma jalan kalau ada
  preset eksplisit (`db.presets` kosong di instance ini); (2) Connection
  Request/refresh on-demand untuk v0.7.3 belum bisa jalan — tunnel VPN
  `test-x86-bajastu` belum pernah handshake, firewall hub-and-spoke v0.6.2
  sengaja dikunci ke satu tujuan FreeRADIUS saja, jaringan ZTE belum punya
  tunnel sama sekali.

**Amendment pasca-tag v0.7.2 (ditemukan saat investigasi v0.7.3, dua klaim
follow-up di atas ternyata salah)**: tunnel WireGuard `test-x86-bajastu`
**tidak pernah mati** — klaim "tidak pernah handshake" berasal dari
mengecek node WireGuard yang salah (`wireguard`/node-1, padahal
`vpn_accounts` NAS ini di-assign ke `vpn-node-2`); dicek ulang di node
yang benar, handshake aktif dengan trafik nyata. Akar masalah Connection
Request sebenarnya ada di `AllowedIPs` WireGuard (cryptokey routing,
dikunci ke `172.28.0.10/32` di kedua ujung), bukan tunnel mati. Juga:
**tidak ada "jaringan ZTE" terpisah** — dicek langsung ke RouterOS API
`test-x86-bajastu` (port API custom, bukan default 8728), device Huawei
(`10.1.12.87`) dan ZTE (`10.1.13.229`) sama-sama lease DHCP aktif dari
satu DHCP server yang sama (`dhcp2`, pool `10.1.0.0/20`) di router yang
sama — satu NAS, satu subnet manajemen, bukan dua lokasi. Lihat entry
v0.7.3 di bawah untuk implementasi perbaikan yang sebenarnya dibutuhkan.

## v0.7.1 — GenieACS Core (pembuka cluster v0.7.0)

Sub-sprint pertama cluster v0.7.0 (GenieACS/TR-069 CPE Management), dipecah
jadi v0.7.1-v0.7.4 sama pola dengan v0.6.0 → v0.6.1-v0.6.5 (lihat
`docs/ROADMAP.md`). BOSS-002 relaxed untuk cluster ini.

- **Deploy stack GenieACS + MongoDB**: 4 container baru
  (`genieacs-cwmp`/`genieacs-nbi`/`genieacs-fs`/`genieacs-ui`, di-build dari
  `docker/genieacs/Dockerfile` — npm install, tidak ada image resmi, sama
  alasan seperti freeradius/openvpn/wireguard/l2tp) + `mongo:8.0` sebagai
  datastore terpisah (BOSS-009: tidak pernah `boss_db`/`radius_db`).
  `genieacs-nbi` **tidak punya host port sama sekali** — hanya reachable
  container-to-container lewat `boss-network`, karena NBI tidak punya
  autentikasi bawaan sendiri (isolasi jaringan adalah lapisan keamanan
  utamanya). `genieacs-cwmp` (port 7547, tempat ONT/CPE pelanggan connect)
  justru harus publik — difronting `boss-nginx` (reverse proxy HTTP) alih-
  alih expose port TR-069 mentah langsung, per keputusan yang dikunci saat
  planning.
- **Bentuk respons NBI diverifikasi nyata, bukan diasumsikan dari
  dokumentasi resmi** (yang tidak memberi contoh JSON lengkap) — SOAP
  Inform mentah dikirim langsung ke `genieacs-cwmp` yang hidup, hasilnya
  dibedah: `_id` formatnya persis `OUI-ProductClass-SerialNumber`, tiap
  parameter TR-069 muncul sebagai object bersarang dengan field `_value`,
  dan `_deviceId` (Manufacturer/OUI/ProductClass/SerialNumber langsung dari
  struct DeviceId milik RPC Inform) ternyata tersedia independen dari root
  TR-098/TR-181 apa pun yang dipakai device. `genieacs-sim` (simulator
  resmi project GenieACS) dicoba dulu sesuai arahan, tapi paketnya (v0.9.0
  di npm) menyasar Node 6.x — dependensi native `libxmljs` gagal build di
  toolchain modern. Diganti SOAP Inform manual, cukup untuk kebutuhan
  verifikasi sprint ini.
- **Penyimpangan dari spec awal**: rencana awal menyebut MAC address
  "biasanya paling reliable" untuk mencocokkan device hasil scan teknisi ke
  device GenieACS. Verifikasi nyata di atas membuktikan sebaliknya — MAC
  tidak punya path parameter TR-069 yang sama di semua vendor (baru
  dipetakan per-vendor di v0.7.2), sedangkan `_deviceId._SerialNumber`
  selalu tersedia dari setiap Inform RPC (field wajib TR-069, bukan
  parameter opsional). `CpeBindingService` karena itu mencocokkan lewat
  **serial number**, bukan MAC.
- **Auto-binding otomatis dari Installation**: hook baru di
  `WorkOrderService::complete()` memanggil
  `CpeBindingService::bindFromWorkOrder()` — best-effort (catch + log,
  tidak pernah menggagalkan penyelesaian work order itu sendiri, pola sama
  dengan `WhatsappGatewayService::buildAndQueue()`). Device yang belum
  pernah inform ke GenieACS sama sekali saat binding **tidak gagal keras**
  — baris `cpe_devices` tetap dibuat dengan `genieacs_device_id` null dan
  `status = pending_first_connect`.
- **Reconciliation job terjadwal** (`ReconcileCpeDevices`, `everyFiveMinutes()`):
  mencocokkan ulang device `pending_first_connect` terhadap GenieACS
  berdasarkan serial number setiap kali dijalankan, untuk kasus umum device
  di-scan/di-bind sebelum online pertama kalinya.
- **Fallback TR-098 → TR-181**: `GenieAcsClientService::getStandardIdentity()`
  mencoba root `InternetGatewayDevice.DeviceInfo.*` dulu, baru
  `Device.DeviceInfo.*` kalau kosong — root mana yang berhasil disimpan ke
  `cpe_devices.tr069_root` supaya query parameter berikutnya (v0.7.2+)
  tidak perlu menebak ulang.
- **API read-only**: `GET /cpe-devices`, `GET /cpe-devices/{id}` — sengaja
  tidak ada endpoint create/update/delete, binding selalu otomatis lewat
  `CpeBindingService`, bukan input admin manual.
- **UI Livewire read-only** (`/cpe-devices`, "Perangkat CPE" di sidebar
  Network): list device reseller-scoped, kolom customer/manufacturer-model/
  serial/status/last inform. Belum ada tombol aksi apa pun (reboot/ganti
  SSID itu scope v0.7.3).
- **Test**: 11 test baru (binding otomatis saat work order completed,
  device belum pernah inform tidak gagal keras, reconciliation job,
  isolasi reseller di API + UI, fallback TR-098/TR-181) — semuanya lulus,
  ditambah full regression 324 test lain di repo tetap hijau.

## v0.6.5 — Dynamic Virtual Server & CoA (penutup v0.6.0)

Sub-sprint terakhir cluster v0.6.0 (FreeRADIUS Integration).

- **Dynamic per-NAS virtual server FreeRADIUS**: `FreeradiusVirtualServerService`
  menulis 2 file per NAS (`listen/nas-{id}.conf`, `clients/nas-{id}.conf`) ke
  volume baru `freeradius_nas_config`, dibaca lewat `$INCLUDE` direktori dari
  `sites-enabled/default`/`clients.conf` — dipatch idempoten oleh
  `docker/freeradius/entrypoint.sh`. Isolasi antar-NAS lewat PORT, bukan IP
  sumber (semua trafik NAS ter-MASQUERADE oleh container VPN node yang
  dilewati, jadi IP sumber tidak bisa membedakan NAS mana pun).
- **5 gap infrastruktur nyata ditemukan & diperbaiki saat deploy** (bukan
  cuma baca dokumentasi FreeRADIUS): `SIGHUP` TIDAK membuka listen socket
  baru (radiusd perlu restart sungguhan — dibuktikan lewat log server +
  `netstat` sebelum/sesudah); FreeRADIUS menolak start kalau direktori
  `$INCLUDE` world-writable (fix: `chgrp www-data + chmod 0770`, bukan
  `0777` seperti volume VPN lainnya); tabrakan port dengan listener
  `inner-tunnel` bawaan FreeRADIUS di 18120 (range dipindah mulai 20000);
  file root-owned dari sesi `tinker` verifikasi bikin `www-data` gagal
  menulis ulang (self-healing chmod tiap siklus poll ~3 detik, kelas bug
  sama seperti insiden PKI OpenVPN "nas-11" v0.6.3); config NAS yang
  tabrakan bikin `radiusd` mati total tanpa auto-restart (supervisor loop
  sekarang cek proses hidup tiap siklus, bukan cuma saat config berubah).
- **Port allocator** (`NasPortAllocatorService`, singleton counter row
  `lockForUpdate()`): `auth_port`/`acct_port` teralokasi otomatis & unik
  per NAS. **`coa_port` SENGAJA TIDAK ikut dialokasikan** — ditemukan
  lewat cek nyata `/radius/incoming/print` di router: port CoA RouterOS
  itu satu pengaturan per-router, bukan per-server-RADIUS, jadi tidak ada
  alasan harus unik seperti auth/acct. Tetap kolom biasa default 3799.
- **Script Generator RADIUS tab**: sekarang pakai `auth_port`/`acct_port`
  asli NAS (dynamic virtual server), bukan lagi port default 1812/1813
  bersama. **Bug nyata ditemukan push pertama ke test-x86-bajastu**: baris
  `/user group add ... policy=...` memuat `!dude`, keyword RouterOS 6.x
  yang sudah dihapus di 7.x — RouterOS menolak SELURUH string policy
  begitu satu token tidak dikenal, sempat salah didiagnosis sebagai
  masalah izin (ada masalah izin nyata terpisah yang kebetulan ditemukan
  lebih dulu). Diperbaiki (buang `!dude`, tambah `!rest-api`).
  **Diverifikasi nyata penuh**: script diterapkan bersih ke router asli,
  `radclient` dapat `Access-Accept` sungguhan lewat port dinamisnya.
- **CoA/Disconnect** (`CoaService`, `POST /nas/{nas}/disconnect`): kirim
  Disconnect-Request/CoA-Request ke NAS (arah kebalikan dari auth/acct —
  BOSS App jadi client, NAS jadi server). Eksekusi radclient WAJIB terjadi
  di dalam container `freeradius` sendiri (dibuktikan lewat
  `/radius/incoming/print` router: validasi CoA berdasarkan `address=`
  entri `/radius`, yang selalu IP statis freeradius) — dikoordinasikan
  lewat antrian file di volume bersama (pola sama seperti config NAS),
  bukan panggilan langsung dari `boss-app`. Firewall exception sempit
  ditambahkan (dikonfirmasi dulu bersama Agung, karena mengubah jaminan
  keamanan satu-arah yang dikunci sejak v0.6.2/v0.6.3):
  `iptables ... -s $FREERADIUS_INTERNAL_IP -j ACCEPT` di container
  openvpn/wireguard, plus route balik dari `freeradius` (resolve nama
  container, bukan IP hardcode, self-healing tiap siklus).
  **Diverifikasi nyata sampai batas yang jujur**: `tcpdump` di tun0
  konfirmasi paket Disconnect-Request benar-benar transit end-to-end
  (routing + firewall exception + eksekusi radclient dari container yang
  benar, semua terbukti). **Belum terverifikasi**: apakah router benar-
  benar mengeksekusi disconnect-nya — `test-x86-bajastu` ternyata router
  produksi nyata dengan 427 sesi pelanggan aktif (bukan lab), dan Agung
  memilih tidak memutus sesi pelanggan asli hanya untuk uji coba. Dicatat
  sebagai item verifikasi tertunda, bukan bug — sama seperti gap QR-scan
  WhatsApp dan retest hardware L2TP sebelumnya.
- **Bug nyata ditemukan & diperbaiki di kode v0.6.1-v0.6.4**:
  `NasIndex::testConnection()` — tes yang sukses dengan password BEDA
  dari yang tersimpan (misal masukin ulang password lama yang benar)
  cuma menyimpan `status`/`last_ping_at`, TIDAK PERNAH menyimpan ulang
  password yang benar itu — akibatnya NAS terlihat "online" sesaat lalu
  "offline lagi" di pemakaian berikutnya, pola yang kemungkinan besar
  adalah akar dari insiden "password 154415" yang pernah diselidiki
  sebelumnya. Sekarang tes sukses dengan password baru otomatis
  menyimpannya.
- **Di luar sprint tapi terjadi di tengah sprint atas permintaan
  eksplisit Agung**: `config('app.timezone')` ternyata hardcode `'UTC'`
  walau root `.env` sudah lama mendeklarasikan `APP_TIMEZONE=Asia/
  Jakarta` (pola bug sama seperti `APP_ENV`) — diperbaiki, dipasangkan
  dengan `migrate:fresh` + reseed penuh (disetujui karena BOSS App
  sendiri belum live production). Ketemu 1 bug urutan nyata saat reseed:
  `VpnServersSeeder` (v0.6.4) mengasumsikan baris node1 sudah ada dengan
  id terendah per protokol (`VpnServer::poolOwnerFor()` bergantung pada
  ini) — kalau dijalankan sebelum node1 dibuat ulang, node2/3 malah jadi
  pool owner secara diam-diam. Ditemukan lewat cek `poolOwnerFor()`
  langsung, bukan diasumsikan; diperbaiki dengan urutan buat ulang yang
  benar.

### Amendment — perbaikan bug rotasi password API NAS (masih v0.6.5, sebelum tag)

- **Bug dikonfirmasi ulang oleh Agung**: `generateRadiusScript()` merotasi
  `nas.api_username`/`api_password` di SETIAP pemanggilan tanpa syarat,
  termasuk saat cuma preview — bukan cuma efek samping ringan, tapi
  penyebab langsung insiden "NAS offline sendiri" yang berulang kali
  dilaporkan sepanjang sprint ini. Diperbaiki tuntas:
  `MikrotikScriptGenerator::radiusScript()` sekarang cuma berisi
  `/radius add`, tidak menyentuh `/user`/`/user group` sama sekali;
  `VpnScriptService::generateRadiusScript()` murni read-only (test baru
  memanggil 5x berturut-turut, assert nol perubahan DB — diverifikasi
  nyata juga terhadap `test-x86-bajastu`: 5x panggilan, `api_password`
  tidak berubah, `testConnection()` tetap berhasil tanpa input ulang).
- **User API khusus BOSS App, terpisah dari kredensial admin asli**
  (root confusion yang bikin bug di atas berdampak besar — kredensial
  admin ASLI ikut rusak, bukan cuma kredensial internal): kredensial
  admin sekarang cuma dipakai sekali pakai lewat modal terpisah ("Buat/
  Perbarui User API" di `/nas`), tidak pernah disimpan. BOSS App
  otomatis membuat user API khusus (`boss-app-api-{nas_id}`, grup
  `boss-app-api`, policy `read,api,password` + deny selebihnya, TANPA
  `!dude`) lewat `NasApiUserProvisioningService`, dan `nas.api_username`/
  `api_password` ter-update ke kredensial baru itu. Grup dapat hak
  `password` (bisa ubah password sendiri) supaya rotasi berikutnya bisa
  self-service tanpa minta kredensial admin lagi.
  **Diverifikasi nyata terhadap test-x86-bajastu**: user `boss-app-api-1`
  benar-benar dibuat di router dengan policy persis seperti dirancang,
  `nas.api_username`/`api_password` di database ter-update, dan
  `testConnection()` berhasil pakai kredensial barunya.

### Amendment — perbaikan black-hole Accounting-Request (masih v0.6.5, sebelum tag)

- **Insiden nyata ditemukan saat uji produksi terhadap `test-x86-bajastu`**:
  entri `/radius` boss-app sempat menunjukkan timeout rate ~61% saat
  diuji, sempat terlihat seperti masalah performa FreeRADIUS serius.
  Investigasi bertahap membuktikan jalur auth-nya sendiri SEHAT (test
  langsung dari IP sumber yang benar dapat Access-Accept instan,
  100/100 sukses di benchmark volume besar) — akar masalah sebenarnya
  adalah `accounting-port` entri itu sengaja diarahkan ke port 1 (tidak
  ada yang dengar) di percobaan sebelumnya, supaya data accounting
  pelanggan asli berhenti terkumpul. Efek sampingnya: SETIAP paket
  Accounting-Request dari SEMUA sesi aktif router (bukan cuma akun uji)
  selalu timeout 100%, mencemari counter `/radius/monitor` dan bikin
  router retry percuma terus-menerus.
- **Perbaikan sesungguhnya, bukan menghidupkan lagi black hole-nya**:
  `docker/freeradius/entrypoint.sh` sekarang juga mem-patch blok
  `accounting {}` bersama di `sites-enabled/default` (idempoten, pola
  sama seperti patch `$INCLUDE` yang sudah ada) — mematikan `detail`
  (log mentah ke file) dan `-sql` (tulis ke `radacct`). FreeRADIUS tetap
  mendengar di accounting-port asli dan tetap membalas
  Accounting-Response yang valid & cepat, cuma tidak lagi menyimpan
  apa pun yang bisa diidentifikasi ke pelanggan — sejalan dengan sikap
  "jangan kumpulkan data yang tidak perlu" yang sudah diterapkan
  sebelumnya di sprint ini. **Diverifikasi nyata**: kirim
  Accounting-Request langsung ke port asli → dapat Accounting-Response
  sungguhan, baris `radacct` tetap 0 sebelum dan sesudah.
  `accounting-port` di entri router juga dikembalikan ke port asli
  (20001) — dilakukan saat entri masih `disabled=true`, jadi tidak ada
  efek langsung ke trafik nyata.
- Entri `/radius` boss-app tetap `disabled=true` (posisi nonaktif) —
  mengembalikannya ke posisi pertama tetap keputusan terpisah yang
  perlu konfirmasi eksplisit lebih dulu, bukan langkah otomatis setelah
  perbaikan ini.

### Amendment — akar masalah sesungguhnya: `require_message_authenticator` (masih v0.6.5, sebelum tag)

- **Bug paling dalam dari seluruh rangkaian investigasi produksi terhadap
  `test-x86-bajastu`, ditemukan lewat pendekatan sistematis bertingkat
  (Level 1-6) mulai dari nol**: setelah black-hole accounting (amendment
  di atas) diperbaiki, entri boss-app diaktifkan lagi untuk diuji —
  tapi `radiusd -X` tetap menunjukkan **nol** `Received Access-Request`
  untuk percobaan `085166445368`, padahal log router terus mencatat
  retry setiap ~90 detik. Dilacak dengan `tcpdump` di 3 titik (masuk
  tunnel WireGuard, keluar node VPN, masuk interface `freeradius`
  sendiri) — paket Access-Request ASLI dari router terbukti **sampai
  utuh** ke `freeradius` (MAC tujuan cocok, checksum UDP valid, dibedah
  hex byte-per-byte: RADIUS Code=1 sah, atribut MS-CHAP2-Response ada),
  statistik kernel (`/proc/net/udp`, `/proc/net/snmp`) nol drop — tapi
  `radiusd` tidak pernah mencatatnya sama sekali, bahkan di mode debug
  penuh.
- **Akar masalah**: `radiusd.conf` punya default bawaan image
  `require_message_authenticator = yes` (mitigasi BlastRADIUS) —
  paket Access-Request TANPA atribut Message-Authenticator langsung
  dibuang diam-diam, sebelum sempat dicatat/diproses sama sekali. Paket
  asli dari RouterOS (dikonfirmasi dari hex dump) memang tidak pernah
  menyertakan atribut ini, sementara `radclient` (alat uji kita)
  otomatis selalu menambahkannya — itu sebabnya SEMUA test manual kita
  sebelumnya (5x, 100x) selalu sukses, sementara trafik NAS asli selalu
  didiamkan tanpa jejak log apa pun. Dari sudut pandang RouterOS ini
  identik dengan timeout biasa (RouterOS memang cuma failover ke server
  RADIUS berikutnya kalau timeout, bukan kalau reject eksplisit) —
  jadi bug ini jugalah alasan sebenarnya kenapa boss-app di posisi
  pertama selama ini tidak pernah benar-benar mengintervensi trafik
  pelanggan (bukan karena aman, tapi karena diam total).
- **Perbaikan**: override `require_message_authenticator = no` di
  `FreeradiusVirtualServerService`, scoped KHUSUS ke definisi client
  per-NAS (`clients/nas-{id}.conf`) — bukan mengubah default global di
  `radiusd.conf`. Ini persis rekomendasi resmi dari dokumentasi
  `radiusd.conf` sendiri untuk skenario "RADIUS client yang belum
  update". Mode `"auto"` sengaja TIDAK dipakai — dokumentasi yang sama
  menyebutkan eksplisit bahwa auto-detect tidak berfungsi untuk client
  yang didefinisikan lewat network/mask (client kita `/24`, bukan IP
  tunggal).
- **Eksekusi dilakukan dengan urutan aman berlapis** (rollback duluan →
  fix → verifikasi terisolasi → baru live lagi, bukan fix-langsung-live):
  1. Entri boss-app di-disable dulu di router, PPP Active dikonfirmasi
     stabil (438 sebelum/sesudah) — memastikan tidak ada risiko
     tambahan sebelum fix diterapkan.
  2. Fix diterapkan (kode + regenerasi config nyata untuk NAS produksi).
  3. **Diverifikasi lewat cara yang sama sekali tidak menyentuh trafik
     produksi**: skrip PHP raw-socket menyusun Access-Request PERSIS
     meniru bentuk byte paket MikroTik asli (tanpa Message-Authenticator,
     enkripsi PAP RFC 2865 manual) dikirim langsung ke FreeRADIUS —
     password benar → `Access-Accept`, password salah → `Access-Reject`
     (membuktikan logika autentikasi tetap utuh, bukan asal-terima).
  4. Baru setelah lolos verifikasi terisolasi, entri boss-app diaktifkan
     kembali di posisi pertama, dipantau ketat tiap 15 detik selama 5
     menit penuh (20 titik cek) dengan auto-rollback siap terpicu kalau
     PPP Active turun >5 dari baseline — **tidak pernah terpicu**, PPP
     Active tetap 437-438 sepanjang window, `rejects` naik wajar (0→7,
     bukti FreeRADIUS kini benar-benar menjawab), `timeouts` tidak
     bertambah sama sekali.
- **Hasil akhir, verifikasi nyata end-to-end pertama yang benar-benar
  berhasil di seluruh investigasi ini**: `085166445368` muncul di
  `/ppp/active/print` dengan `address=10.0.1.144` (IP asli dari pool
  `PPPOE-REMOTE`), `uptime=5m3s`, `radius=true` — sesi PPPoE asli,
  terautentikasi via FreeRADIUS kita, stabil tersambung, bukan lagi
  cuma Access-Accept di level paket.
- **Catatan untuk NAS Mikrotik lain di masa depan**: jangan asumsikan
  RouterOS selalu mengirim Message-Authenticator di Access-Request PPP
  CHAP/MSCHAP — versi/konfigurasi tertentu tidak menyertakannya. Kalau
  NAS baru menunjukkan gejala serupa (retry terus-menerus, timeout di
  `/radius/monitor`, tapi `radiusd -X` tidak pernah mencatat
  "Received Access-Request" sama sekali), cek dulu byte mentah paketnya
  (`tcpdump`) untuk keberadaan atribut ini sebelum menyalahkan performa
  atau jaringan.

### Penutupan v0.6.5 — dari hasil eksperimen manual jadi benar-benar dikelola BOSS App

- **Port allocator akhirnya diverifikasi race-safe secara nyata**: 5 proses
  `NasPortAllocatorService::allocate()` ditembak bersamaan ke Postgres
  produksi (bukan sqlite test) — semua port hasil alokasi unik, tanpa
  duplikat. Sebelumnya klaim "race-condition-safe" hanya didukung test
  sekuensial.
- **Config `/radius` di router `test-x86-bajastu` sekarang hasil generate
  resmi Script Generator BOSS App, bukan lagi hasil edit manual berkali-kali
  sepanjang investigasi**: entri manual lama (comment `added by boss-app`)
  dihapus, digantikan entri baru (comment `boss-radius`) lewat alur
  fetch+import normal — persis yang akan dialami admin lewat UI. Diverifikasi
  identik secara fungsional (address/port/secret/timeout) dengan konfigurasi
  yang sudah terbukti jalan; `PPP Active` tidak terganggu sepanjang proses
  (438 sebelum, 437-438 sesudah).
- **CoA/Disconnect akhirnya diuji nyata untuk pertama kalinya** (sebelumnya
  cuma terverifikasi sampai level transit paket, eksekusi oleh router tidak
  pernah dikonfirmasi) — memakai `085166445368` (akun test milik sendiri,
  aman diputus, lihat catatan akun permanen di CLAUDE.md), bukan pelanggan
  asli. Hasil: Disconnect-Request terkirim benar (format, retry, secret
  semua sesuai) tapi tanpa balasan — ditelusuri sampai akar masalah: rute
  CoA `freeradius` untuk subnet WireGuard NAS ini mengarah ke node pool
  owner (node1), padahal akun WireGuard NAS ini nyambung ke node sibling
  (node2) yang tidak pernah node1 ajak handshake. **Ini mengonfirmasi
  known limitation v0.6.4 yang sudah didokumentasikan di `CoaService`'s
  docblock sejak awal** — bukan bug baru, dan tetap backlog terpisah
  (CoA router yang sadar multi-node), bukan scope v0.6.5.
- **`085166445368` resmi jadi akun test permanen** (dikonfirmasi Agung,
  bukan data test yang perlu dibersihkan) — gratis/tidak ditagih, dipakai
  untuk QA RADIUS/VPN berkelanjutan di sprint-sprint mendatang. Skema
  `radcheck`/`radreply` bawaan FreeRADIUS tidak punya kolom deskripsi,
  jadi statusnya didokumentasikan di CLAUDE.md (bagian "Permanent test
  account") supaya tidak disalahartikan sebagai data tertinggal oleh
  audit/cleanup di masa depan.
- Regression suite penuh: **313 test, 803 assertion, semua lolos** — tidak
  ada dampak dari seluruh rangkaian perbaikan v0.6.5 ke modul lain.

**v0.6.5 resmi ditutup — sekaligus menutup seluruh cluster v0.6.0 (FreeRADIUS
Integration, v0.6.1-v0.6.5).** Ringkasan penutupan cluster lengkap ada di
`docs/ROADMAP.md`.

## v0.6.4 — Multi-Node VPN Pool & Auto-Switch Failover

- **Keputusan arsitektur dikonfirmasi bersama Agung sebelum implementasi**:
  pool **3 node VPN nyata** (bukan cuma skema siap N>1 tapi 1 baris
  terisi seperti rencana awal v0.6.2) untuk OpenVPN dan WireGuard —
  masing-masing protokol 3 container terpisah (`openvpn-node1/2/3`,
  `wireguard-node1/2/3`), sesuai pola single-responsibility container
  yang sudah dikunci sejak v0.6.3. L2TP TIDAK ikut pool (known
  limitation belum selesai, tetap 1 node). **Ketiga node per protokol
  berbagi trust domain yang sama** — OpenVPN: volume PKI (`vpn_pki`)
  di-mount ke ketiga node, satu CA, cert yang diterbitkan sekali valid
  di node manapun. WireGuard: server keypair + direktori peer
  (`vpn_wg_data`) di-mount ke ketiga node, pubkey server identik di
  semua node — diverifikasi nyata: `wireguard-node2` langsung memakai
  pubkey persis sama dengan node1 begitu container pertama kali boot.
  Ini yang membuat auto-switch script cukup ganti `connect-to`/
  `endpoint-port` tanpa re-import credential apa pun.
- Migration baru `vpn_servers.port` (per-node, sebelumnya port
  OpenVPN/WireGuard adalah satu config global) + `VpnServersSeeder`
  idempoten mengisi 4 baris node baru (bukan seed dummy — baris nyata
  untuk container yang benar-benar hidup).
- `VpnProvisioningService`: node BARU dipilih berdasarkan load
  distribution (`status=online` DAN `current_clients < max_clients`,
  diurutkan dari yang paling longgar, `lockForUpdate()` race-condition-
  safe) — TAPI `internal_ip` tetap selalu dialokasikan dari "pool
  owner" (node1, baris ber-id terendah per protokol) supaya konsisten
  dengan CCD OpenVPN yang cuma pernah ditulis ke node1 dan direktori
  peer WireGuard yang shared. `VpnServer::poolOwnerFor()` — konvensi
  baru, didokumentasikan lengkap di CLAUDE.md.
- **Health-check terjadwal nyata** (`VpnCheckNodeHealth`,
  `->everyMinute()`): tiap node openvpn/wireguard menulis heartbeat
  timestamp ke volume shared setiap ~10 detik (OpenVPN: loop baru;
  WireGuard: numpang di reconcile loop yang sudah ada); command
  membaca heartbeat, update `status` (online/full/offline). **Bug nyata
  ditemukan & diperbaiki lewat uji jalan otomatis, bukan cuma baca
  kode**: `boss-scheduler` (container yang benar-benar mengeksekusi
  `schedule:run`) TIDAK mount volume `vpn_pki`/`vpn_wg_data` sama
  sekali — health-check yang berjalan lewat scheduler akan SELALU
  menandai semua node offline meski sehat, sampai volume itu
  ditambahkan ke service `boss-scheduler` di docker-compose.yml.
  Diverifikasi nyata: `boss-scheduler` dibiarkan jalan otonom 2 siklus
  penuh (2 menit), status tetap `online` konsisten, tidak flapping.
- **Auto-switch scheduler Mikrotik** (`MikrotikScriptGenerator`) —
  ditambahkan ke tab VPN Script setelah konfigurasi utama: cek
  FreeRADIUS tiap 30 detik lewat tunnel, kalau gagal pindah port ke
  node lain (siklus sekuensial, port dialokasikan berurutan oleh
  `VpnServersSeeder`). **3 bug nyata ditemukan berturut-turut lewat
  deploy sungguhan ke `test-x86-bajastu`** (bukan diasumsikan benar dari
  membaca dokumentasi RouterOS saja), semua bermuara pada bagaimana
  `/import` memproses file `.rsc`, berbeda dari mengirim command yang
  sama langsung lewat API:
  1. `/system scheduler add ... on-event={...multi-baris...}` — blok
     curly-brace TIDAK PERNAH benar-benar tersimpan sebagai isi
     `on-event` (dikonfirmasi kosong lewat query `.proplist=on-event`
     langsung, bukan cuma tampilan terpotong). Diperbaiki dengan pola
     yang SUDAH TERBUKTI dipakai scheduler bawaan router ini sendiri
     (`schedule-script-speed` dkk): `on-event` menunjuk ke NAMA script
     terpisah, bukan kode inline.
  2. Script terpisah itu sendiri (`/system script add ... source={...}`)
     mengalami masalah PERSIS SAMA — `source` juga kosong. Diperbaiki
     dengan mengubah `source=` jadi SATU BARIS string (dipisah `;`,
     pola sama one-liner fetch+import yang sudah dipakai di seluruh
     modul ini), bukan blok multi-baris.
  3. Setelah `source` akhirnya terisi, SETIAP referensi `$variable` di
     dalamnya ternyata di-expand kosong oleh `/import` (RouterOS
     meng-evaluasi `"$var"` di dalam string terkutip terhadap scope
     SAAT IMPORT dijalankan, bukan scope runtime script itu sendiri) —
     dibuktikan dengan mengirim string identik langsung lewat API
     (bukan file `.rsc`), yang tersimpan sempurna tanpa masalah apa
     pun. Diperbaiki dengan menulis backslash literal di depan setiap
     `$` (`\$var`, bukan `$var`) — dikonfirmasi langsung ke router
     bahwa `/import` lalu menyimpannya sebagai `$var` literal.
- **Verifikasi failover nyata, end-to-end, bukan simulasi**: setelah
  ketiga bug di atas teratasi, `docker compose stop wireguard-node2`
  (node yang sedang dipakai `test-x86-bajastu`) — dalam ~60 detik
  (2 siklus scheduler 30 detik), `endpoint-port` peer WireGuard di
  router **berpindah otomatis** dari port node yang mati ke port node
  berikutnya, handshake baru terbentuk, dan `/ping` ke FreeRADIUS lewat
  tunnel baru berhasil 4/4 paket (0% packet loss) — tanpa satu pun
  intervensi manual. Node yang dimatikan dihidupkan kembali setelahnya.
- Bonus fix kecil tapi nyata: `File::get()` untuk WireGuard server
  public key membawa trailing newline dari `wg pubkey` yang bisa
  merusak parsing quoted-string di script yang digenerate — sekarang
  di-`trim()`.
- Dropdown protokol Script Generator: L2TP/IPsec tetap tampil (tidak
  disembunyikan) dengan label eksplisit "(known limitation)" + catatan
  detail di UI, bukannya dibiarkan seolah setara OpenVPN/WireGuard.

**Catatan verifikasi tertunda, bukan bug atau sprint belum selesai**:
implementasi dan fungsinya sudah terbukti nyata sepenuhnya lewat API
langsung ke `test-x86-bajastu` (load-distribution, health-check,
auto-switch failover end-to-end semua dikonfirmasi bekerja) — tapi
**jalur UI Livewire (Script Generator, tombol-tombol terkait) untuk
skenario multi-node/failover ini belum pernah dicoba manual oleh Agung
lewat browser**. Agung akan menyiapkan router Mikrotik terpisah khusus
untuk testing UI menyeluruh nanti. Detail arsitektur lengkap ada di
`docs/ROADMAP.md` dan CLAUDE.md bagian "Multi-Node VPN Pool & Auto-Switch
Failover (v0.6.4)".

## v0.6.3 — Multi-Protokol VPN (WireGuard, L2TP/IPsec) & Script Generator Mikrotik

- **2 keputusan arsitektur diselesaikan bersama Agung sebelum implementasi**
  (lihat `docs/ROADMAP.md`): (A) WireGuard & L2TP/IPsec di container Docker
  terpisah dari `openvpn`, konsekuensinya `vpn_servers.protocol_support`
  (json) diganti kolom `protocol` polos — sekarang satu baris per (host,
  protocol); (B) tab RADIUS Script Generator sementara pakai port default
  FreeRADIUS (1812/1813), bukan `nas.auth_port`/`acct_port`, sampai v0.6.5.
- Container Docker baru `wireguard` (alpine + wireguard-tools 1.0.20210914,
  host port UDP 51820) dan `l2tp` (alpine + strongSwan 5.9.13 + xl2tpd
  1.3.18, host port UDP 500/4500/1701) — strongSwan dipilih atas libreswan
  karena rilis Alpine-nya lebih rutin dan lebih umum jadi referensi setup
  L2TP/IPsec PSK.
- Migration alter `vpn_servers` (protocol_support json -> protocol string,
  backfill data existing row v0.6.2 otomatis) + kolom baru
  `vpn_accounts.public_key` (WireGuard, bukan secret — tidak perlu
  encrypted).
- `App\Services\Network\VpnProvisioningService::provision()`/`revoke()`
  di-refactor bercabang per protokol:
  - **WireGuard**: keypair digenerate di `boss-app` (`wg genkey`/`wg
    pubkey` via Process facade — murni kripto, tidak butuh live interface).
    Peer changes TIDAK bisa dijalankan langsung dari `boss-app` (beda
    network namespace) — dipakai pola **reconcile loop** (idiom sama
    `boss-scheduler`/`boss-whatsapp-worker`): `boss-app` menulis file
    `[Peer]` per-NAS ke volume bersama, container `wireguard` menggabung +
    `wg syncconf` tiap 10 detik. Private key HANYA dikembalikan sekali
    lewat properti PHP transient (`VpnAccount::$wireguardPrivateKey`,
    BUKAN kolom Eloquent) — tidak pernah disimpan di DB.
  - **L2TP/IPsec**: PSK IPsec satu untuk seluruh node (infra-level, env
    `L2TP_IPSEC_PSK`), auth per-NAS di layer PPP (`chap-secrets`, akhirnya
    memakai kolom `vpn_accounts.password` yang sejak v0.6.2 sudah
    disiapkan tapi belum terpakai). `chap-secrets` di-**regenerate
    seluruhnya** dari DB tiap provision()/revoke() (bukan append/remove
    baris) — `pppd` di-spawn `xl2tpd` fresh per percobaan koneksi, jadi
    perubahan langsung efektif tanpa reload.
- `App\Services\Network\MikrotikScriptGenerator` (pure templating, tanpa
  I/O) + `App\Services\Network\VpnScriptService` (orkestrasi — provisioning
  kalau belum ada akun, baca material yang tersedia per protokol, panggil
  generator). Script idempotent (hapus interface lama dulu) dan isolasi
  routing (routing-mark + rule untuk OpenVPN/L2TP; `allowed-address`
  WireGuard sendiri sudah cukup) — NAS tidak pernah dapat default route
  lewat tunnel.
- UI baru **Script Generator** (`App\Livewire\Network\VpnScriptGenerator`,
  `/vpn-script-generator`, cluster sidebar "Network" baru) — 2 tab (VPN
  Script, RADIUS Script), reseller-scoped via `NasPolicy`. Generate RADIUS
  Script juga meng-**(re)generate `nas.api_username`/`api_password`** —
  menutup celah dengan `NasService::testConnection()` (v0.6.1) yang
  butuh kredensial API Mikrotik yang benar-benar valid.

Bug nyata ditemukan & diperbaiki lewat verifikasi end-to-end sungguhan
(provisioning WireGuard/L2TP asli, peer terbukti muncul/hilang di `wg show`
setelah reconcile loop, `chap-secrets`/`ipsec.conf` terbukti ter-render
benar di dalam container):
- `wg`/`wg genkey`/`wg pubkey` dipanggil dari `boss-app` tapi
  `wireguard-tools` cuma ter-install di image `openvpn`— lupa ditambah ke
  `docker/php/Dockerfile`. Kali ini paket baru ditaruh di layer `apk add`
  **terpisah setelah** layer `docker-php-ext-install sockets` yang lambat,
  supaya tidak mengulang kesalahan cache-invalidation v0.6.1/v0.6.2 (±7
  menit kompilasi ulang percuma).
- `Process::fake()` di test butuh pattern `wg genkey`/`wg pubkey` juga
  diawali `*` (gotcha yang sama dengan `easyrsa` v0.6.2).
- Bug kecil di test sendiri (bukan implementasi): asumsi awal `/29` CIDR
  menghasilkan 4 alamat usable, ternyata 5 (`CidrRange` v0.6.2 memang benar
  — perhitungan manual di test yang salah).
- `provision()`'s final `->fresh()` re-query diam-diam membuang properti
  PHP transient `$account->wireguardPrivateKey` (instance model baru dari
  DB tidak mungkin membawa properti non-kolom) — ditemukan lewat test
  sendiri, diperbaiki dengan tidak memanggil `->fresh()` di akhir
  `provision()` (semua `issue*Credentials()` sudah memutasi instance yang
  sama via `->update()`, jadi tidak ada data basi).

Out-of-scope v0.6.3, di-declare eksplisit sebagai backlog: multi-node pool +
health-check + failover + `VpnServerController` (v0.6.4 — baris
`vpn_servers` WireGuard/L2TP sprint ini dibuat manual lewat `tinker`, sama
seperti OpenVPN di v0.6.2), dynamic virtual server + CoA + port unik
per-NAS sungguhan (v0.6.5 — RADIUS Script masih port default sampai saat
itu), force-disconnect sesi WireGuard/L2TP yang sudah aktif saat revoke
(WireGuard: peer hilang max ~10 detik setelah revoke, bukan instan; L2TP:
sesi PPP yang sudah jalan tidak diputus paksa), verifikasi koneksi sungguhan
dari NAS Mikrotik asli (tidak ada perangkat fisik/virtual di environment
ini — sama seperti gap WhatsApp QR-scan v0.4.0).

Detail lengkap ada di CLAUDE.md bagian "Multi-Protocol VPN & Script
Generator (v0.6.3)".

**Amendment (gap ditemukan setelah verifikasi awal, ditutup di sesi yang
sama sebelum v0.6.3 di-tag)**: dropdown "Pilih NAS" di Script Generator
ternyata kosong pada percobaan pertama — didiagnosis dulu sebelum
diasumsikan bug: bukan bug query, tapi memang belum ada satu pun NAS asli
untuk tenant demo (`super_admin@boss.local`) — 4 baris `nas` yang sempat
ada semuanya sisa data pollution dari sesi verifikasi tinker v0.6.1-v0.6.3
di tenant palsu yang tidak terhubung user manapun (sudah dibersihkan). Akar
masalahnya: **UI manajemen NAS memang belum pernah dibangun** — v0.6.1
sengaja API-only. Ditambahkan sebagai prasyarat Script Generator, bukan
scope baru:
- `App\Livewire\Network\NasIndex` (`/nas`, cluster sidebar "Network" di
  atas Script Generator) — list + create/edit, pola admin-vs-reseller sama
  `WhatsappGatewayIndex` (v0.4.0). `mikrotik_ip` sekarang bisa diisi manual
  lewat UI ini (koreksi dari pembatasan `StoreNasRequest`/v0.6.1 yang
  sebelumnya melarangnya sama sekali) — otomatis terkunci (read-only)
  begitu NAS punya `vpn_accounts` aktif, TANPA membangun mekanisme
  auto-sync `internal_ip` -> `mikrotik_ip` yang sungguhan (itu tetap gap
  terpisah yang sudah tercatat, UI ini cuma mencegah admin bentrok dengan
  auto-management yang belum ada).
- Tombol "Tes Koneksi" di form create/edit — memakai `RouterOsGateway`
  yang sama dengan `NasService::testConnection()` (v0.6.1, tidak ditulis
  ulang), tapi dipanggil dengan nilai form YANG SEDANG DIKETIK (belum
  tentu tersimpan) lewat instance `Nas` transient — `NasService`'s method
  sendiri tidak bisa dipakai langsung untuk NAS baru karena butuh row yang
  sudah persisted. Untuk NAS yang sudah tersimpan, `status`/`last_ping_at`
  tetap ter-update ke row asli setelah tes.
- Diverifikasi nyata (bukan asumsi): NAS yang dibuat lewat UI baru ini
  langsung muncul di query dropdown Script Generator tanpa cache apa pun
  (query yang sama, tidak ada layer cache di antaranya).
- 12 test baru (`NasIndexLivewireTest`): render, create, validasi
  (radius_secret wajib), blank-submit tidak menghapus secret tersimpan,
  mikrotik_ip terkunci, reseller-scoping (create + isolasi lihat/edit),
  tes koneksi sukses/gagal + tidak persist untuk NAS baru.

**Amendment kedua (3 perbaikan dari hasil testing manual via UI, ditutup
sebelum tag)**:
- **Bug nyata**: `MikrotikScriptGenerator` selalu memakai mekanisme
  `/routing table` + `routing-table=` (RouterOS 7-only) untuk isolasi
  routing OpenVPN/L2TP, walau diklaim "RouterOS-generic v6.x dan v7.x" —
  `/routing table` tidak ada sama sekali di RouterOS 6.x (didesain ulang
  total di v7). Diperbaiki: bercabang per versi — v6 pakai `routing-mark=`
  langsung di `/ip route add` + `/ip firewall mangle` (gaya lama, sesuai
  referensi MixRadius), v7 tetap `/routing table`+`routing-table=`+
  `/routing rule`. `dst-address` sendiri sudah benar ada di kedua jalur
  sejak awal (diverifikasi, bukan bug). Semua script (protokol apa pun)
  sekarang juga menghapus keempat interface client (ovpn/sstp/l2tp/pptp)
  + WireGuard di awal, bukan cuma interface protokol yang sedang
  digenerate.
- **Bug nyata (akar masalah nas-11), ditemukan lewat investigasi langsung
  ke volume PKI**: `chmod -R 0777` di entrypoint `openvpn` cuma jalan
  SEKALI saat boot. Setiap invokasi `easyrsa` berikutnya (dari `boss-app`,
  berjalan sebagai `www-data` di request HTTP asli) menulis ulang
  `pki/index.txt`/`serial`/`index.txt.attr` dengan permission ketat
  bawaan OpenSSL — request berikutnya oleh user/proses lain gagal
  *Permission denied* setelah sempat mencetak progress dots OpenSSL
  (".....+++...."), yang sebelumnya ikut ter-dump mentah ke pesan error
  API. Diperbaiki: `VpnProvisioningService` sekarang menangkap stdout/
  stderr terpisah, membersihkan pesan error (buang noise progress dots,
  ambil baris terakhir yang relevan, log FULL output ke Laravel log), dan
  me-restore permission 0777 setelah SETIAP invokasi easyrsa (sukses
  maupun gagal) — bukan cuma sekali di boot. **Diverifikasi nyata**:
  provisioning OpenVPN sebagai `www-data` sungguhan (`docker compose exec
  --user www-data`, bukan root seperti sesi tinker sebelumnya) berhasil
  dua kali berturut-turut tanpa chmod manual di antaranya; NAS asli milik
  user (`nas-11`) yang tadinya gagal sekarang berhasil generate cert
  (`cert_serial` terisi, file `.crt` tersimpan dengan pemilik `www-data`).
- **Bug nyata tambahan, ditemukan saat menguji tombol revoke-lalu-generate-
  ulang**: `vpn_accounts.internal_ip` punya unique constraint GLOBAL
  (termasuk baris yang sudah revoked) sejak v0.6.2 — begitu satu IP pernah
  dipakai, IP itu tidak bisa dipakai ulang selamanya walau sudah
  dibebaskan di `vpn_ip_pool`, karena baris lama (revoked) masih menempati
  nilai itu di index unique. Migration baru menggantinya dengan partial
  unique index (`WHERE status = 'active'`) — pola sama dengan
  `whatsapp_sessions` (v0.4.0) — supaya IP yang sudah revoked benar-benar
  bisa dipakai ulang.
- Tombol **"Cabut & Generate Ulang"** ditambahkan di Script Generator —
  muncul otomatis saat generate gagal karena NAS sudah punya akun aktif
  untuk protokol yang dipilih (kondisi ini reachable untuk WireGuard,
  karena private key lama sudah hilang). Satu klik: revoke akun lama →
  provision baru → generate script, dengan `wire:confirm` sebagai
  peringatan aksi destruktif (koneksi lama terputus).

**Amendment ketiga (4 perbaikan dari hasil test langsung di router Mikrotik
asli, ditutup sebelum tag)**:
- **Bug nyata, dilaporkan dari perangkat asli**: `navigator.clipboard.writeText()`
  gagal senyap di tombol "Salin" Script Generator karena server ini masih HTTP
  polos (bukan `localhost`, jadi bukan secure context browser) — Clipboard API
  memang tidak tersedia sama sekali di kondisi ini, bukan bug logic. Diperbaiki
  dengan fallback `document.execCommand('copy')` lewat textarea sementara,
  dipilih otomatis berdasar `window.isSecureContext`. **Diverifikasi nyata**
  lewat Playwright headless browser sungguhan yang login & klik tombol di
  server dev ini sendiri (bukan localhost HTTPS) — dikonfirmasi
  `isSecureContext=false`, `navigator.clipboard` memang undefined, dan
  `document.execCommand('copy')` benar-benar terpanggil (di-hook langsung,
  bukan diasumsikan) dengan UI feedback "Tersalin!" tampil.
- **Bug nyata, dari log strongSwan router asli (`NO-PROPOSAL-CHOSEN`)**:
  `ipsec.conf.template` cuma menawarkan `aes256/aes128/3des-sha1-modp1024`
  dengan strict mode (`!`) — RouterOS 7 klien asli menawarkan proposal ESP
  yang tidak persis cocok (termasuk NULL-encryption/HMAC-SHA1, ditemukan dari
  referensi traffic MixRadius yang berhasil konek). Diperbaiki: proposal
  ESP/IKE diperluas (tambah `modp2048`, tambah `null-sha1` di ESP) dan strict
  mode (`!`) dilepas supaya strongSwan juga mencocokkan default bawaannya
  sendiri kalau penawaran RouterOS tidak persis sama. **Batasan verifikasi
  jujur**: dikonfirmasi config valid & strongSwan start bersih tanpa error
  fatal (`ipsec statusall` menunjukkan connection ter-load benar), TAPI
  `NO-PROPOSAL-CHOSEN` benar-benar hilang + IPsec SA established + l2tp-client
  "connected" di RouterOS 7 asli **belum bisa dikonfirmasi dari environment
  ini** (tidak ada akses perangkat Mikrotik fisik/virtual) — perlu tes ulang
  langsung oleh Agung di router asli.
- **Perubahan mekanisme, dari laporan reboot-prompt yang menggantung**: paste
  script panjang (terutama OpenVPN yang berisi private key PEM) langsung ke
  terminal interaktif RouterOS ditemukan bisa memicu prompt konfirmasi tak
  terkait ("Save changes? [y/N]") di tengah paste, memutus sisa perintah.
  Diganti dengan mekanisme **fetch+import**: UI sekarang menampilkan satu
  baris pendek (`/tool fetch ... ;/import file-name=...;/file remove ...`)
  alih-alih script penuh untuk di-paste. `App\Services\Network\
  ScriptDownloadTokenService` menyimpan script lengkap di cache (Redis)
  dengan token acak 48-karakter, TTL 10 menit, **sekali pakai**
  (`Cache::pull` — atomic get-and-delete). Endpoint baru
  `GET /vpn-script-generator/download/{token}.rsc`
  (`VpnScriptDownloadController`) sengaja TANPA middleware auth — `/tool
  fetch` RouterOS tidak mengirim cookie/token, keamanan bertumpu pada token
  itu sendiri (entropi tinggi + TTL pendek + sekali pakai), bukan permanent
  public URL. Skema URL (`http`/`https`) diambil dari request yang sedang
  berjalan (`request()->getSchemeAndHttpHost()`), BUKAN hardcode — server
  ini masih HTTP polos, otomatis berubah ke `https` sendiri begitu TLS
  terpasang, tanpa ubah kode. Pola yang sama dipakai untuk SEMUA jenis
  script (VPN maupun RADIUS), bukan cuma OpenVPN, demi konsistensi UX. Script
  lengkap tetap bisa dilihat lewat `<details>` collapsible di bawah
  one-liner (transparansi/audit), hanya bukan target copy-paste utama lagi.
  **Diverifikasi nyata** end-to-end: Playwright browser sungguhan
  men-generate one-liner asli (`mode=http` terkonfirmasi, bukan hardcode
  https), lalu `curl` langsung ke endpoint download membuktikan isi script
  yang diunduh benar dan fetch KEDUA ke token yang sama mengembalikan 404
  (sekali-pakai benar-benar berfungsi). **Batasan verifikasi jujur**: yang
  BISA dikonfirmasi dari sini adalah server-side (isi script benar, token
  sekali pakai, tidak hardcode https). Bahwa prompt `[y/N]` benar-benar tidak
  lagi menggantung proses saat `/tool fetch` + `/import` dijalankan di
  RouterOS asli **belum bisa dikonfirmasi dari environment ini** — perlu tes
  langsung oleh Agung di router asli.
- **Bug nyata, kontras tombol**: tombol "Cabut & Generate Ulang" pakai
  `bg-red-600 text-white` — ternyata SATU-SATUNYA tempat di seluruh app yang
  memakai pola filled-button untuk aksi destruktif (pola dominan app-wide
  adalah `text-red-600 hover:underline`, dipakai di 6+ tempat lain — lihat
  `nas-index.blade.php`, `customer-show.blade.php`, dll). Akar masalah
  ganda: (1) Tailwind CSS bundle belum di-rebuild sejak view ini dibuat, jadi
  `bg-red-600` memang tidak ada sama sekali di CSS terkompilasi (bukan typo
  class), (2) bahkan setelah rebuild, class itu tetap tidak konsisten dengan
  pola app. Diperbaiki dengan pindah ke pola `text-red-600 hover:underline`
  yang sudah established, sekaligus rebuild Tailwind bundle
  (`app-BrUlYJCB.css`) untuk semua view v0.6.3 yang belum pernah di-rebuild
  sejak dibuat (termasuk `nas-index.blade.php`). **Diverifikasi nyata**:
  Playwright mengambil `getComputedStyle` warna tombol "Hapus" (pola yang
  sama) di halaman NAS sungguhan — hasilnya `oklch(0.637 0.237 25.331)`,
  merah Tailwind `red-600` asli, bukan putih/transparan.
- 4 test baru (`VpnScriptDownloadTest`): download token valid, token sekali
  pakai (fetch kedua 404), token tidak dikenal 404, `mode=http` bukan
  hardcode https. `VpnScriptGeneratorLivewireTest` diperbarui untuk
  memverifikasi `fetchCommand` (one-liner) terisi, bukan cuma
  `generatedScript`.

**Amendment keempat (regresi 500 di tombol "Cabut & Generate Ulang",
ditemukan lewat pemakaian nyata setelah Amendment ketiga, ditutup sebelum
tag)**: klik tombol untuk NAS dengan protokol WireGuard menghasilkan 500
generik. **Akar masalah ditemukan dari `storage/logs/laravel.log` asli**
(bukan tebakan): `mkdir(): Permission denied` di
`VpnProvisioningService::issueWireGuardCredentials()` saat menulis fragment
peer ke `/vpn-wg-data/peers`. Investigasi lanjutan (`stat` langsung dari
dalam container) menemukan `/etc/wireguard` (mount point volume
`vpn_wg_data`) sendiri berpermission `700 root:root` — bawaan default paket
`wireguard-tools` Alpine (dirancang untuk mengamankan private key di setup
single-user), ikut terbawa ke volume Docker saat pertama kali dibuat.
Entrypoint `wireguard` cuma pernah `chmod` anak-anaknya (`peers/` dan kedua
file key), tidak pernah direktori volume itu sendiri — `www-data` sama
sekali tidak bisa traverse masuk ke `/etc/wireguard`, jadi
`File::isDirectory()`/`makeDirectory()` gagal EACCES sebelum sempat
menyentuh `peers/` yang sebenarnya sudah permisif. Diperbaiki dengan
`chmod 0755 "$WG_DIR"` tambahan di `docker/wireguard/entrypoint.sh`. Volume
`vpn_l2tp_secrets` diperiksa juga (kekhawatiran kelas bug yang sama) —
ternyata aman, `755` sejak awal karena dibuat murni oleh `mkdir -p` tanpa
paket yang menyuntik permission ketat. **Diverifikasi nyata, ulang persis
skenario yang dilaporkan**: rebuild container `wireguard`, lalu Playwright
browser sungguhan login → pilih NAS `test-x86-bajastu` (nas-11, yang
memang sudah punya akun WireGuard aktif) → protokol WireGuard → Generate →
tombol "Cabut & Generate Ulang" muncul → klik → confirm popup asli → hasil
one-liner fetch+import baru tampil, TANPA 500. Dikonfirmasi juga di DB
(akun lama berubah `revoked`, akun baru `active`) dan di log (tidak ada
entri error/warning baru selama window itu).

**Amendment kelima (verifikasi mendalam terhadap NAS Mikrotik produksi asli
`test-x86-bajastu`, RouterOS 7.11, via `RouterOsApiGateway` — bukan simulasi,
ditutup sebelum tag)**: 3 laporan baru dari test langsung ke router asli
setelah Amendment ketiga/keempat (fetch+import). Untuk pertama kalinya di
sprint ini, seluruh siklus test→diagnosis→perbaikan→verifikasi ulang
dilakukan otonom lewat API langsung ke router produksi (bukan minta admin
tes manual berulang), dengan `/system/script` sebagai pengganti paste
terminal manual dan `tcpdump`/`tshark` di level container maupun host untuk
bukti paket nyata.

1. **OpenVPN — bug nyata, syntax error saat import certificate+private key**:
   `MikrotikScriptGenerator::openVpnScript()` sejak awal meng-embed konten
   PEM certificate/key sebagai teks mentah di badan script `.rsc` (awalnya
   dimaksudkan cuma sebagai komentar instruksional untuk upload manual, tapi
   fetch+import Amendment ketiga mengeksekusi SELURUH isi file sebagai
   command RouterOS) — baris PEM mentah bukan syntax command yang valid,
   `/import` gagal begitu certificate/key sungguhan terlibat. Diperbaiki
   dengan mendesain ulang, bukan sekadar membetulkan escaping: setiap file
   (`ca.crt`/`client.crt`/`client.key`) sekarang punya token
   download-sekali-pakai sendiri (mekanisme sama dengan
   `ScriptDownloadTokenService`), diambil via `/tool fetch` terpisah tepat
   sebelum `/certificate import` — tanpa langkah manual tambahan buat admin,
   dan PEM tidak pernah lagi terlihat di browser (private key kini "shown
   once" sama seperti WireGuard). **Diverifikasi nyata**: script dijalankan
   via API di router asli, tuntas tanpa syntax error, kedua certificate
   berhasil ter-import (`private-key=true` pada client cert), file sementara
   di router bersih terhapus.
   - **Bonus temuan saat verifikasi konektivitas nyata (di luar scope
     laporan awal, tapi memblokir hal yang sedang diverifikasi)**: server
     OpenVPN mewajibkan `tls-crypt`, padahal fitur itu baru ada di RouterOS
     mulai 7.17rc3 — router produksi ini (dan kemungkinan besar mayoritas
     RouterOS 7.x yang sudah deployed) masih di bawah versi itu, membuat
     server secara struktural TIDAK MUNGKIN cocok dengan client manapun yang
     lebih lama. Dilepas dari `docker/openvpn/server.conf.template` (kembali
     ke TLS berbasis certificate murni, tanpa lapisan HMAC tambahan
     tls-crypt/tls-auth — tls-auth juga tidak dipakai karena RouterOS's
     `/interface ovpn-client add` tidak punya parameter inline untuknya).
     Setelah dilepas, real TLS handshake sukses (`VERIFY OK`) — tapi
     ditemukan gejala baru (reconnect loop dengan `ECONNREFUSED` di sisi
     server) yang **belum diselesaikan**, di luar cakupan laporan asli;
     dihentikan investigasinya di titik ini untuk fokus ke 2 bug yang
     eksplisit dilaporkan.
2. **WireGuard — 2 bug nyata ditemukan berurutan, keduanya sekarang
   terverifikasi teratasi total secara end-to-end**:
   - Bug pertama: `allowed-address` pada peer RouterOS **tidak otomatis
     mengisi routing table** — bertentangan dengan asumsi lama di CLAUDE.md
     ("allowed-address sudah cukup jadi isolasi routing"). Dibuktikan
     langsung via `/ip route print`: satu-satunya rute ke IP internal
     FreeRADIUS adalah rute lama milik interface OpenVPN, tidak aktif.
     Diperbaiki dengan menambahkan `/ip route add` eksplisit di
     `wireGuardScript()`, sama seperti OpenVPN/L2TP.
   - Bug kedua, jauh lebih dalam — ditemukan lewat binary-search sistematis
     (bukan tebakan) setelah bug pertama ternyata belum cukup: ping masih
     100% gagal walau rute sudah benar dan handshake WireGuard sukses.
     Serangkaian tes isolasi (`allowed-address` permisif vs sempit,
     kombinasi sisi client/server, address `/24` vs `/32`) mempersempit
     masalah ke satu variabel: `AllowedIPs` di **sisi server** — hanya
     `0.0.0.0/0` yang berhasil, subnet manapun lain (termasuk yang secara
     teori seharusnya cocok) gagal. `rx` byte counter naik (paket
     terenkripsi diterima & valid secara kriptografi) tapi `tx` statis dan
     0 paket pernah muncul di `wg0` — bukti paket dibuang SETELAH decrypt.
     Root cause sebenarnya: **NAT rule di router produksi ini sendiri**
     (`"NAT KE ANTEN"`, `src-address-list=!NO-NAT`, tanpa batasan interface
     keluar) ikut menerapkan source-NAT ke traffic tunnel WireGuard kita
     karena subnet VPN (`172.23.194.0/24`/`172.23.195.0/24`/`172.23.196.0/24`)
     tidak terdaftar di address-list `NO-NAT` router — source paket berubah
     dari `172.23.195.2` jadi alamat publik NAT router SEBELUM sempat
     dienkripsi, sehingga tidak pernah cocok `AllowedIPs` server manapun
     selain yang benar-benar permisif. **Bukan bug di kode/config BOSS App
     sama sekali** — diperbaiki di firewall router (ketiga subnet VPN
     ditambahkan ke `address-list NO-NAT` via API, atas persetujuan
     eksplisit sebelum mengubah konfigurasi produksi). **Diverifikasi nyata,
     end-to-end, dengan config production penuh** (bukan test permisif):
     script digenerate lewat `VpnScriptService` resmi, `allowed-address=/32`
     seperti semestinya — hasil 6/6 paket ping diterima, 0% packet loss,
     RTT ~6ms.
3. **L2TP/IPsec — `NO-PROPOSAL-CHOSEN` benar-benar teratasi, tapi
   ditandai sebagai known limitation, bukan selesai**: root cause SEBENARNYA
   bukan soal cipher/hash/lifetime yang diduga sebelumnya (Amendment
   ketiga) — ditemukan lewat log internal `charon` sendiri, yang sebelumnya
   tidak pernah bisa dibaca sama sekali (charon default log ke syslog,
   container ini tidak punya syslog daemon; ditambahkan `syslogd` permanen
   di entrypoint untuk observability). Log itu menunjukkan
   `"no IKE config found for 172.28.0.13...144.79.52.0"` — charon menolak
   SEBELUM sempat mengevaluasi satu pun proposal cipher, karena `left=` di
   `ipsec.conf` di-pin ke IP publik server sementara Docker men-DNAT paket
   masuk menjadi IP internal container sebelum sampai ke charon, jadi
   definisi `conn` tidak pernah cocok apa pun. Diperbaiki dengan
   `left=%any` — pola standar untuk IPsec responder di belakang
   NAT/port-forward container. **Diverifikasi nyata**: IKE_SA + CHILD_SA
   established dan STABIL 1+ menit dengan DPD (Dead Peer Detection) sukses
   bolak-balik berkali-kali — `NO-PROPOSAL-CHOSEN` tuntas hilang. Perbaikan
   `address-list NO-NAT` (poin 2) juga dicoba terhadap L2TP dengan hipotesis
   akar masalah yang sama — **tidak berdampak**, dikonfirmasi lewat capture
   ulang di level host.
   **Tapi**: lapisan L2TP/PPP di ATAS IPsec yang sudah established itu tidak
   pernah benar-benar terlindungi. `tcpdump` di level **host** (interface
   publik server, bukan di dalam container — sesuai saran eksplisit
   sebelum dicoba, untuk menyingkirkan kemungkinan Docker bridge/NAT
   sebagai penyebab) membuktikan RouterOS mengirim paket kontrol L2TP
   (port 1701, `SCCRQ`) sebagai **UDP polos, bukan terbungkus ESP** — 0
   paket ESP ditemukan di seluruh capture, meski IKE Phase 1 dan Phase 2
   (Quick Mode) sukses negosiasi tepat sebelumnya. Docker networking
   (bridge vs host mode) dan NAT eksplisit disingkirkan sebagai penyebab —
   paket sampai utuh identik di level host maupun container. Root cause
   sebenarnya ada di bagaimana mode sederhana RouterOS
   `l2tp-client use-ipsec=yes` menerapkan (atau gagal menerapkan) Security
   Policy Database-nya sendiri secara internal untuk melindungi trafik
   L2TP — di luar kendali/kemampuan diagnosis dari sisi server BOSS App.
   **Keputusan eksplisit**: L2TP/IPsec ditandai sebagai *known limitation*
   dan sprint v0.6.3 tetap ditutup — OpenVPN dan WireGuard sudah fully
   functional dan terverifikasi nyata di router produksi yang sama, L2TP
   tidak memblokir keduanya. Detail arsitektur lengkap ada di
   `docs/ROADMAP.md`.

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
