# WhatsApp Gateway — Peta API & Perilaku (investigasi Langkah 0, migrasi ke whatsmeow)

Dokumen ini adalah hasil investigasi menyeluruh terhadap `whatsapp-gateway/` (Node.js + Baileys) yang
berjalan HARI INI, sebagai acuan implementasi kalau/ketika migrasi ke Go + `whatsmeow` dieksekusi.
**Bukan** dokumen desain Go — murni pemetaan "apa yang harus tetap bekerja sama persis dari sudut pandang
Laravel", supaya sisi Laravel (`App\Services\Whatsapp\*`, `SendWhatsappMessageJob`, dst) idealnya tidak
perlu berubah sama sekali kecuali isi container gateway-nya diganti.

Sumber: pembacaan langsung `whatsapp-gateway/index.js`, `src/sessionManager.js`, `src/hmac.js`,
`src/webhook.js`, `docker-compose.yml`, kedua `.env.example`, plus grep menyeluruh terhadap `app/` Laravel
untuk setiap titik pemanggilan nyata.

---

## 1. Permukaan API HTTP (Node → Laravel arahnya kebalik, ini gateway sebagai SERVER)

Base URL dari sisi Laravel: `config('services.whatsapp_gateway.url')` (env `WHATSAPP_GATEWAY_URL`,
default `http://whatsapp-gateway:3000`). Semua route (kecuali tidak ada — semua endpoint gateway pakai
HMAC) diproteksi middleware `verifyHmac` yang sama.

### Skema autentikasi HMAC (WAJIB direplikasi persis di implementasi baru)

- **Header**: `X-Whatsapp-Timestamp` (unix seconds, string) + `X-Whatsapp-Signature` (hex).
- **Signing string**: `"{timestamp}.{raw_body}"` — `raw_body` adalah body JSON APA ADANYA (byte-untuk-byte
  string yang dikirim Laravel, BUKAN hasil re-encode `JSON.parse`→`JSON.stringify` di sisi Node — kalau
  di-parse ulang lalu di-stringify ulang, whitespace/urutan key bisa beda dan signature gagal verify).
  Node menangkap `req.rawBody` lewat `express.json({ verify: (req,res,buf) => req.rawBody = buf.toString() })`
  justru untuk menghindari masalah ini.
- **Algoritma**: `HMAC-SHA256(secret, signing_string)`, hex digest.
- **Secret**: `WHATSAPP_GATEWAY_HMAC_SECRET` — HARUS identik byte-untuk-byte di root `.env` (dibaca
  Laravel via `config('services.whatsapp_gateway.hmac_secret')`) dan `whatsapp-gateway/.env`. Ini secret
  level-infra (seperti `APP_KEY`), bukan kredensial bisnis.
- **Toleransi replay**: 300 detik (5 menit) — request dengan timestamp di luar jendela ini DITOLAK meski
  signature-nya benar secara byte.
- **Perbandingan**: `crypto.timingSafeEqual` (Node) / `hash_equals()` (PHP) — wajib timing-safe di kedua
  arah kalau nanti diimplementasi ulang di Go (`hmac.Equal` di paket `crypto/hmac` Go sudah timing-safe
  secara built-in).
- Body kosong (`GET` tanpa body) tetap ikut di-sign — `raw_body` jadi string kosong `''`, signing string
  jadi `"{timestamp}."`.

Implementasi PHP: `App\Support\WhatsappHmac::sign($body, $timestamp)` / `::verify(...)`. Implementasi Node:
`whatsapp-gateway/src/hmac.js` — deklarasi eksplisit di CLAUDE.md sebagai "mirror line-for-line, jaga tetap
sinkron kalau berubah."

### Endpoint 1 — `GET /sessions`

- **Guna**: daftar SEMUA sesi yang saat ini dikenal proses Node (in-memory), dipakai
  `WhatsappSessionService::reconcileFromGateway()` (dipanggil `whatsapp:check-session-health`, `->hourly()`
  di `routes/console.php`) sebagai jaring pengaman kalau webhook `connection.update` gagal terkirim.
- **Request**: tanpa body.
- **Response**:
  ```json
  { "success": true, "message": "OK", "data": null, "meta": {},
    "sessions": [ { "sessionKey": "direct", "status": "...", ... }, ... ] }
  ```
  **CATATAN PENTING — inkonsistensi envelope**: array sesi ada di key **`sessions`** di level TERATAS
  (sibling dari `data`), BUKAN di dalam `data`. Laravel membacanya lewat `$response->json('sessions', [])`.
  Implementasi baru HARUS mempertahankan bentuk ini persis (atau — kalau mau dirapikan — sisi Laravel
  perlu di-update bersamaan, bukan diam-diam berharap kompatibel).
- Isi persis tiap elemen `sessions[]` = apa pun yang dikembalikan `sessionManager.listStates()` (state
  in-memory per sesi: minimal `sessionKey` + `status` Baileys-side; field lain tidak dikonsumsi Laravel
  secara eksplisit selain untuk pencocokan `sessionKey`).

### Endpoint 2 — `GET /sessions/:sessionKey/health`

- **Guna**: dicek — **TIDAK ADA satu pun caller Laravel** yang memanggil endpoint ini (dikonfirmasi via
  grep menyeluruh `app/Services/`, `app/Jobs/`, `app/Console/`, `app/Http/Controllers/`). Endpoint ini
  murni ada untuk debugging manual (curl langsung), tidak masuk jalur produksi.
- **Response**: `{ success, message, data: <state sesi tunggal>, meta: {} }` — di sini `data` dipakai
  wajar (beda dari endpoint `/sessions` di atas).
- **Implikasi migrasi**: BOLEH tidak direplikasi 100% identik kalau merepotkan — tidak ada kontrak Laravel
  yang bergantung padanya. Tapi murah untuk tetap disediakan (berguna buat debugging whatsmeow juga).

### Endpoint 3 — `GET /sessions/:sessionKey/qr`

- **Guna**: ambil (atau minta ulang) QR code base64 PNG untuk sesi yang belum terhubung. Dipanggil
  `WhatsappSessionService::refreshQrCode()`.
- **Response sukses**: `{ success: true, message: "OK", data: null, meta: {}, qr_code_data: "<base64 png data URI>" }`
  — sekali lagi `qr_code_data` di level TERATAS, bukan di dalam `data`.
- **Response gagal**: HTTP 500, `{ success: false, message: "<error message>", data: null, meta: {} }`.
- Perilaku sisi Node (`sessionManager.getOrRefreshQr`): kalau sesi belum ada di memori, buat baru
  (`connect()`), tunggu event `qr` dari Baileys, encode ke base64 PNG (pakai lib `qrcode`), simpan ke state
  in-memory sesi itu supaya panggilan berikutnya (sebelum di-scan) bisa mengembalikan QR yang sama tanpa
  memicu QR baru berulang-ulang.
- **Whatsmeow equivalent**: `client.GetQRChannel(ctx)` mengembalikan channel Go yang mengirim event
  `"code"` (string QR mentah, BUKAN base64 PNG — whatsmeow tidak encode ke gambar sendiri, itu tanggung
  jawab pemanggil pakai lib QR generator Go seperti `github.com/skip2/go-qrcode`). Kontrak HTTP
  `qr_code_data` sebagai base64 PNG data URI harus tetap dihasilkan di sisi Go supaya UI Livewire
  (`<img src="{{ $qrCodeData }}">`) tidak perlu berubah.

### Endpoint 4 — `POST /sessions/:sessionKey/pair` (fitur baru, sprint `whatsapp-gateway-reliability`)

- **Guna**: alternatif QR — native Baileys `requestPairingCode()`, admin masukkan nomor HP dulu, dapat
  kode 8-digit untuk diketik manual di aplikasi WhatsApp (Linked Devices → Link with phone number).
  Dipanggil `WhatsappSessionService::requestPairingCode()`.
- **Request body**: `{ "phone_number": "62812xxxx" }` (format internasional tanpa `+`, sudah dinormalisasi
  sisi Laravel via `App\Support\WhatsappPhone::normalize()` sebelum dikirim).
- **Response sukses**: `{ success: true, message: "OK", data: null, meta: {}, pairing_code: "ABCD-1234" }`.
- **Response gagal validasi** (nomor kosong): HTTP 422.
- **Response gagal proses**: HTTP 500, `message` berisi alasan (mis. "session already connected", "session
  already has a paired identity — wipe first").
- Perilaku Node (`sessionManager.requestPairingCode`): menolak kalau sesi SUDAH `connected` atau sudah
  punya `creds.json` tersimpan (sesi yang sudah pernah pairing tidak boleh diminta kode baru tanpa wipe
  eksplisit dulu) — sebelum meminta kode, folder `auth_state/{sessionKey}` di-WIPE dulu (fresh state),
  baru `connect()`, baru panggil `sock.requestPairingCode(digitsOnly)`.
- **Whatsmeow equivalent**: `client.PairPhone(ctx, phone, showPushNotification, client.PairClientChrome)`
  mengembalikan string kode langsung — API-nya lebih simpel dari Baileys (tidak perlu await event terpisah).

### Endpoint 5 — `POST /sessions/:sessionKey/send`

- **Guna**: kirim 1 pesan teks. Dipanggil `SendWhatsappMessageJob::sendToGateway()` — SATU-SATUNYA jalur
  pengiriman pesan nyata di seluruh codebase.
- **Request body**: `{ "phone_number": "62812xxxx", "message": "<teks, sudah di-render dari template>" }`.
- **Response sukses**: `{ success: true, message: "Sent", data: null, meta: {} }` — HTTP 200. Laravel cek
  `$response->successful()` saja (2xx), tidak parse field lain.
- **Response gagal**: HTTP 502, `{ success: false, message: "<error message Baileys>", data: null, meta: {} }`
  — pesan error inilah yang tersimpan mentah ke `whatsapp_message_logs.failed_reason` (lihat "device
  removed"/"session unhealthy" dst di riwayat CLAUDE.md).
- **Timeout internal Node** (`SEND_TIMEOUT_MS = 20000`, `Promise.race` di `sessionManager.sendMessage`):
  kalau `sock.sendMessage()` Baileys menggantung >20 detik, di-force-timeout dan dikembalikan sebagai error
  ("send timeout after 20000ms") — bukan menggantung selamanya. Laravel sendiri set `Http::timeout(35)`
  (sedikit di atas 20s ini, biar error asli dari Node yang nyampe, bukan cURL timeout generik).
- **Normalisasi nomor JID** (bug v0.9.6, root cause OTP timeout — WAJIB dipertahankan di Go): nomor lokal
  Indonesia (`08xxx`) harus dinormalisasi ke `62xxx` SEBELUM dibentuk jadi JID
  (`{nomor}@s.whatsapp.net`) — kalau tidak, `sock.sendMessage()` menggantung sampai timeout karena JID
  tidak sah. Baileys: fungsi `toJid()` lokal di `sessionManager.js`. Whatsmeow: format JID beda
  (`types.JID{User: "62812xxx", Server: "s.whatsapp.net"}`, dibentuk via `types.NewJID(...)` atau
  `client.Store.ID` helper) — normalisasi nomor tetap harus dilakukan di level yang sama sebelum
  membentuk JID, siapa pun yang membentuknya (Go atau tetap di Laravel via `WhatsappPhone::normalize()` —
  saat ini SUDAH dilakukan dobel, defense-in-depth, di kedua sisi).

### Ringkasan bentuk response — WAJIB dipertahankan di implementasi baru

| Field | Lokasi | Endpoint yang pakai |
|---|---|---|
| `success`, `message`, `data`, `meta` | selalu ada, level teratas | semua |
| `sessions` | level teratas (BUKAN di `data`) | `GET /sessions` |
| `qr_code_data` | level teratas | `GET /sessions/:key/qr` |
| `pairing_code` | level teratas | `POST /sessions/:key/pair` |

Kode status HTTP yang dipakai: `200` (sukses), `401` (HMAC invalid), `422` (validasi input gagal — body
kosong/field wajib hilang), `500` (kegagalan proses internal — QR/pairing), `502` (kegagalan kirim pesan
— dipilih beda dari 500 supaya Laravel bisa membedakan "gateway itu sendiri error" vs "pesan spesifik ini
gagal dikirim", meski saat ini `SendWhatsappMessageJob` tidak benar-benar membedakan keduanya, cuma cek
`successful()`).

---

## 2. Penanganan Multi-Sesi

- **Satu proses Node, satu `SessionManager` instance, satu `Map` in-memory** (`this.sessions`, key =
  `sessionKey` string) — menyimpan socket Baileys aktif + state per sesi. TIDAK ADA isolasi
  proses/container per sesi — semua reseller + sesi "direct" hidup dalam satu proses Node yang sama,
  dibedakan murni oleh key di `Map`.
- **`sessionKey`** (`App\Models\WhatsappSession::sessionKeyFor(?int $resellerId)`): `(string) $resellerId`
  untuk sesi milik reseller, atau literal `'direct'` untuk pelanggan tanpa reseller/ISP sendiri. Nilai ini
  yang dipakai di URL path (`/sessions/{sessionKey}/...`), nama folder `auth_state/{sessionKey}/`, DAN
  suffix nama queue Redis (`whatsapp-{sessionKey}`).
- **Connect-lock** (`this.connectLocks = new Map<sessionKey, Promise>`, ditambahkan sprint
  `whatsapp-gateway-reliability` — fix race condition nyata): dua request bersamaan untuk `sessionKey`
  yang sama (mis. dua panggilan `GET /sessions/{key}/qr` beruntun sebelum yang pertama selesai) WAJIB
  tidak memicu dua `connect()`/socket Baileys ganda untuk sesi yang sama — request kedua menunggu promise
  yang sama dengan request pertama, bukan membuat koneksi baru. **Whatsmeow equivalent**: pola yang sama
  (map mutex per sessionKey) tetap perlu direplikasi — ini bukan sesuatu yang otomatis "hilang" karena
  ganti library, ini murni disiplin kode gateway sendiri terlepas dari library WhatsApp yang dipakai di
  baliknya.
- **`restoreAll()`** — dipanggil sekali saat proses Node boot (`app.listen(...)` callback di `index.js`):
  scan folder `auth_state/`, untuk tiap subfolder yang berisi `creds.json` valid, panggil `connect()` ulang
  supaya sesi yang tadinya sudah ter-pairing otomatis nyambung lagi tanpa perlu scan ulang. Ini mekanisme
  KUNCI yang menjaga sesi WhatsApp tetap hidup lintas restart container — WAJIB ada ekuivalennya di
  whatsmeow (di sana, biasanya berupa loop `sqlstore.Container.GetAllDevices()` lalu `client.Connect()`
  per device saat servis Go start).
- **Tidak ada rate-limit/isolation ANTAR sesi di level gateway** — rate limiting (lihat poin 4) seluruhnya
  di sisi Laravel (`SendWhatsappMessageJob::applyRateLimitDelay()`), dan karena tiap sesi punya QUEUE Redis
  terpisah (`whatsapp-{sessionKey}`), satu reseller yang macet/di-rate-limit tidak memblokir antrean
  reseller lain — tapi ini murni desain di sisi Laravel/Redis, gateway Node sendiri tidak tahu-menahu soal
  pemisahan ini, dia cuma menerima `POST /send` satu per satu apa adanya.

---

## 3. Persistensi Sesi

### Format Baileys saat ini (`useMultiFileAuthState`, dari `node_modules/@whiskeysockets/baileys`)

- Satu **folder per sesi**: `auth_state/{sessionKey}/` (bind-mounted volume, path `WA_AUTH_DIR` env,
  default `/app/auth_state` di dalam container — dikonfirmasi lewat inspeksi live: sesi `direct` saat ini
  cuma berisi `.gitkeep` alias BENAR-BENAR KOSONG/logged-out saat investigasi ini dilakukan).
- **File per sesi, semua JSON plain-text**:
  - `creds.json` — kredensial identitas utama (noise key, identity key, signed pre-key, registration id,
    nomor telepon setelah pairing, dll — inti "siapa akun WhatsApp ini").
  - `{type}-{id}.json` untuk tiap entri "keys" — `type` mencakup kategori seperti `pre-key`,
    `session`, `sender-key`, `sender-key-memory`, `app-state-sync-key`, `app-state-sync-version` (protokol
    Signal/E2E yang dipakai WhatsApp — banyak file kecil, bisa ratusan per sesi aktif lama).
  - Nama file di-sanitasi (`fixFileName`): `/` → `__`, `:` → `-` (karena `id` sering mengandung `:`, mis.
    `1234567890:1@s.whatsapp.net`).
  - Serialisasi custom (`BufferJSON.replacer`/`.reviver`) — beberapa nilai adalah `Buffer` Node yang
    di-encode/decode khusus, bukan JSON standar polos.
  - Setiap baca/tulis file dikunci per-path pakai `async-mutex` (`Map<path, Mutex>`) — menghindari race
    kalau ada dua operasi baca/tulis file yang sama nyaris bersamaan.
- **Tidak ada database SQL sama sekali di sisi Node** — 100% filesystem, per-file. Ini yang jadi motivasi
  migrasi (folder sisi container = rawan hilang saat redeploy/recreate kalau volume-nya tidak persisten,
  meski di setup SEKARANG `auth_state/` sudah bind-mounted volume, jadi secara teknis SUDAH bertahan lintas
  container recreate — masalah WAHA yang dikutip di `docs/whatsapp-gateway-alternatif-evaluasi.md` soal
  "redeploy menghapus sesi" spesifik untuk pola WAHA sendiri, bukan pola bind-mount manual yang sudah
  dipakai gateway custom ini).

### Yang perlu diterjemahkan ke whatsmeow

- **whatsmeow TIDAK mengekspos API "baca/tulis file JSON sendiri"** seperti Baileys — dia langsung
  mengharapkan implementasi `sqlstore.Container` (paket resmi `go.mau.fi/whatsmeow/store/sqlstore`) yang
  membungkus SEMUA state sesi (setara `creds.json` + semua file `{type}-{id}.json` di atas, plus tabel
  tambahan miliknya sendiri seperti `whatsmeow_contacts`, `whatsmeow_chat_settings`, dll — skema sudah
  didefinisikan resmi oleh library, bukan sesuatu yang perlu dirancang manual) ke dalam tabel-tabel SQL.
  Driver yang didukung out-of-the-box: **SQLite** (`modernc.org/sqlite` atau `mattn/go-sqlite3`, satu file
  `.db`) DAN **PostgreSQL** (`lib/pq`/`pgx`) — keduanya first-class, bukan salah satu "workaround".
- **Rekomendasi konkret untuk dipertimbangkan (sesuai instruksi Agung)**: pakai `boss-postgresql` yang
  SUDAH ADA, bukan file SQLite terpisah di dalam volume Docker gateway — alasannya:
  1. **Backup otomatis** — `scripts/backup.sh` (BOSS-012) kemungkinan besar sudah men-dump `boss_db`
     secara rutin; sebuah file SQLite di volume Docker terpisah gampang terlewat dari cakupan backup itu
     kecuali ditambahkan manual sebagai langkah baru.
  2. **Konsistensi arsitektur BOSS-009**: BOSS-009 mensyaratkan "database terpisah secara logis" antara
     `boss_db`/`radius_db`/`genieacs_db`/`librenms_db` — sesi WhatsApp BUKAN salah satu dari keempat itu,
     jadi tidak melanggar aturan itu untuk taruh di database TERPISAH lagi (`whatsapp_db`?) di dalam
     `boss-postgresql`, ATAU sebagai skema/tabel tambahan di `boss_db` itu sendiri. Ini keputusan desain
     yang masih terbuka — dua opsi realistis:
     - (a) Skema/tabel baru DI DALAM `boss_db` (via migration Laravel biasa, tapi tabel-tabelnya dikelola
       whatsmeow sendiri lewat `sqlstore.New(...)` — bukan Eloquent model) — paling simpel operasional,
       satu koneksi Postgres saja untuk seluruh stack.
     - (b) Database Postgres BARU (`whatsapp_db`) di CONTAINER `boss-postgresql` yang SAMA (bukan
       instance Postgres terpisah seperti `freeradius-db`) — mengikuti pola "1 container Postgres bisa
       menaungi >1 database logis" yang MEMANG sudah jadi kebiasaan project ini di level filosofi
       BOSS-009 (biarpun BOSS-009 sendiri bicara soal container terpisah untuk radius/genieacs/librenms,
       WhatsApp bukan salah satu servis eksternal berat itu, jadi taruh di container yang sama tapi
       database logis terpisah tetap konsisten dengan semangatnya).
     Keputusan (a) vs (b) BELUM diputuskan di investigasi ini — sengaja diserahkan ke tahap perencanaan
     implementasi (di luar scope Langkah 0 ini).
  3. Kalau tetap pakai SQLite per alasan kesederhanaan awal, minimal WAJIB: volume Docker yang sama
     persis presisinya dengan `auth_state/` sekarang (bind-mount, bukan volume anonim) + entri baru di
     `scripts/backup.sh` supaya file `.db`-nya ikut ter-backup — jangan sampai tersirat "sudah aman" tanpa
     verifikasi eksplisit.
- **Migrasi data sesi LAMA ke whatsmeow TIDAK MUNGKIN dilakukan langsung** — format kredensial Baileys
  (Signal protocol state miliknya sendiri) tidak kompatibel biner dengan skema tabel whatsmeow, walau
  keduanya sama-sama mengimplementasikan protokol WhatsApp Web/Signal. **Konsekuensi nyata**: setiap sesi
  yang sudah ter-pairing hari ini (reseller manapun + "direct") HARUS di-scan/pairing ULANG dari nol
  begitu berpindah ke whatsmeow — tidak ada jalan otomatis memindahkan kredensial. Ini poin penting untuk
  tahap perencanaan cutover nanti (bukan sesuatu yang bisa "auto-migrate" diam-diam).

---

## 4. Fitur Lain yang Wajib Ada di Versi Baru

| Fitur | Implementasi saat ini (Baileys) | Catatan untuk whatsmeow |
|---|---|---|
| **Kode Pairing** | `sock.requestPairingCode(digitsOnly)`, wipe `auth_state` dulu kalau sudah pernah pairing | `client.PairPhone(...)` — API lebih simpel, tapi alur "wipe-dulu-kalau-sudah-pernah" tetap harus direplikasi di level gateway Go |
| **Reconnect + exponential backoff** | 5s → 60s, dipicu event `connection.update` dengan `connection === 'close'` dan alasan BUKAN logout permanen | whatsmeow punya event `*events.Disconnected`/`*events.LoggedOut` sendiri lewat event bus (`client.AddEventHandler`) — logika backoff (increment delay, cap di 60s) tetap kode gateway sendiri, bukan bawaan library |
| **`DisconnectReason.badSession` (500) → wipe + tunggu re-pair** | Baileys-spesifik, `DisconnectReason` enum | whatsmeow punya `events.LoggedOut.Reason` sendiri (`events.ConnectFailureReason`) — perlu dipetakan ulang kode/makna errornya, TIDAK identik 1:1 dengan enum Baileys |
| **`connectLocks` (fix race kondisi v0.9.9)** | `Map<sessionKey, Promise>` di `SessionManager` | Pola ini independen dari library WhatsApp yang dipakai — WAJIB direplikasi persis di gateway Go, bukan otomatis "hilang" karena bahasa/library beda |
| **Webhook status ke Laravel** | `notifySessionStatus()` di `src/webhook.js` — POST HMAC-signed ke `{LARAVEL_BASE_URL}/api/v1/whatsapp/webhook/session-status`, fire-and-forget (gagal kirim = tidak retry, cuma di-log — dipulihkan lewat `whatsapp:check-session-health` polling `->hourly()`) | Kontrak HTTP + HMAC ini WAJIB identik persis di sisi Go — payload JSON, header, endpoint tujuan, semuanya harus sama dengan yang `WhatsappWebhookController::sessionStatus()` (Laravel) harapkan hari ini |
| **`markOnlineOnConnect: false`** | opsi Baileys, sengaja tidak menandai akun "online" begitu connect (privasi/hindari status "online" palsu di WhatsApp asli) | Cek opsi setara di `whatsmeow.Client` (kemungkinan default behavior beda, perlu verifikasi eksplisit saat implementasi, bukan diasumsikan sama) |
| **`syncFullHistory: false`** | opsi Baileys, hindari sinkronisasi riwayat chat penuh saat pairing (gateway ini cuma perlu KIRIM pesan, tidak perlu riwayat chat masuk) | whatsmeow punya `client.Store.ID` dan opsi history sync sendiri — perlu dicek defaultnya, mungkin perlu dimatikan eksplisit juga |
| **`SEND_TIMEOUT_MS = 20000` (Promise.race)** | pembatas eksplisit supaya `sendMessage()` yang menggantung tidak menggantung selamanya | Di Go, pola setara pakai `context.WithTimeout(ctx, 20*time.Second)` dilewatkan ke `client.SendMessage(ctx, ...)` |
| **Normalisasi nomor `0xxx`/`62xxx`/`+62xxx`/`8xxx` → JID** | fungsi `toJid()` lokal | Wajib direplikasi persis — ini FIX BUG NYATA v0.9.6 (root cause OTP timeout berbulan-bulan), bukan cuma nice-to-have |
| **Rate limiting** | **SELURUHNYA di sisi Laravel**, BUKAN di gateway Node sama sekali — `SendWhatsappMessageJob::applyRateLimitDelay()` (`sleep(random_int(min,max))`, `WhatsappGatewaySettings::current()`, default 5-10 detik per pesan) + retry/backoff 30s/2menit/5menit via `$this->release()` di Laravel (tidak ada throttle middleware Express apa pun di `index.js`) | **Tidak perlu diimplementasikan ulang di Go sama sekali** — kontrak `POST /send` gateway tetap "terima satu pesan, kirim sekarang juga, tidak ada antrean/delay internal gateway" — persis prinsip pemisahan tanggung jawab yang sudah ada hari ini |

---

## 5. Semua Titik Ketergantungan (Call Sites) di Laravel

Dikonfirmasi via grep menyeluruh `app/Services/`, `app/Jobs/`, `app/Console/`, `app/Http/Controllers/`
untuk referensi `whatsapp_gateway`/`WhatsappHmac`/pola URL `/sessions/{`:

| File | Method | Endpoint gateway yang dipanggil |
|---|---|---|
| `App\Services\Whatsapp\WhatsappSessionService` | `refreshQrCode()` | `GET /sessions/{key}/qr` |
| `App\Services\Whatsapp\WhatsappSessionService` | `requestPairingCode()` | `POST /sessions/{key}/pair` |
| `App\Services\Whatsapp\WhatsappSessionService` | `reconcileFromGateway()` | `GET /sessions` |
| `App\Jobs\SendWhatsappMessageJob` | `sendToGateway()` (private) | `POST /sessions/{key}/send` |

**`GET /sessions/{key}/health` — TIDAK ADA caller Laravel sama sekali** (dikonfirmasi, hanya hit false-
positive tak terkait dari modul LibreNMS `/health/...` saat grep `/health` di seluruh `app/`).

### File-file terkait lain (bukan pemanggil HTTP langsung, tapi bagian dari kontrak keseluruhan)

- **`App\Http\Controllers\Api\V1\WhatsappWebhookController::sessionStatus()`** — penerima webhook DARI
  Node (`POST /api/v1/whatsapp/webhook/session-status`, route publik tanpa Sanctum — autentikasi murni
  lewat verifikasi HMAC yang sama). Selalu balas HTTP 200 apa pun hasilnya (pola sama seperti
  `XenditWebhookController`).
- **`App\Http\Controllers\Api\V1\WhatsappSessionController`** — REST admin-facing (Sanctum-protected)
  untuk CRUD sesi dari sisi BOSS App sendiri (`index`/`show`/`refreshQr`) — TIDAK memanggil gateway
  langsung, semuanya lewat `WhatsappSessionService`.
- **`App\Models\WhatsappSession`** — `sessionKey()`/`static sessionKeyFor(?int $resellerId): string`,
  `$fillable`: `tenant_id, reseller_id, phone_number, status, qr_code_data, last_connected_at,
  last_disconnected_at`. `status` di-cast ke enum `App\Enums\WhatsappSessionStatus`
  (`QrPending`/`Connected`/`Disconnected`/`LoggedOut`).
- **`App\Console\Commands\WhatsappQueueNames`** — `php artisan whatsapp:queue-names`, dipakai
  `boss-whatsapp-worker`'s entrypoint (`docker-compose.yml`) buat resolve nama queue Redis dinamis
  (`whatsapp-{sessionKey}`) tiap 5 menit. TIDAK memanggil gateway sama sekali — murni baca `boss_db`.
- **`App\Console\Commands\WhatsappCheckSessionHealth`** — `whatsapp:check-session-health`, `->hourly()` di
  `routes/console.php`, memanggil `WhatsappSessionService::reconcileFromGateway()` (yaitu `GET /sessions`).
- **`App\Support\WhatsappHmac`** — implementasi PHP HMAC sign/verify, dipakai `SendWhatsappMessageJob`
  DAN `WhatsappSessionService` (untuk `qr`/`pair`/`reconcileFromGateway`) DAN
  `WhatsappWebhookController` (verifikasi webhook masuk).
- **`App\Support\WhatsappPhone::normalize()`** — normalisasi nomor Indonesia SEBELUM dikirim ke gateway
  (defense-in-depth, gateway Node sendiri JUGA menormalisasi via `toJid()` — dobel, sengaja).

**Tidak ditemukan** pemanggilan lain (Livewire component, controller lain, job lain) yang bicara langsung
ke gateway di luar 4 titik di atas — semua akses HTTP-ke-gateway terpusat di `WhatsappSessionService` +
`SendWhatsappMessageJob`, persis seperti yang didokumentasikan CLAUDE.md sejak v0.4.0 ("centralized
in a single Node.js service, accessed by BOSS App via internal REST API").

---

## 6. Setup Docker Saat Ini

### `docker-compose.yml` — service `whatsapp-gateway`

```yaml
whatsapp-gateway:
  build: ./whatsapp-gateway
  container_name: whatsapp-gateway
  restart: unless-stopped
  environment:
    - PORT=3000
    - WHATSAPP_GATEWAY_HMAC_SECRET=${WHATSAPP_GATEWAY_HMAC_SECRET}
    - LARAVEL_BASE_URL=http://boss-nginx
  volumes:
    - ./whatsapp-gateway/auth_state:/app/auth_state
  networks:
    - boss-network
  # TIDAK ADA host port dipublikasikan (BOSS-010) — hanya reachable dari
  # dalam boss-network (boss-app, boss-worker, boss-whatsapp-worker).
```

- **Tidak ada `ipv4_address` yang di-pin** untuk `whatsapp-gateway` (beda dari `freeradius`/
  `genieacs-cwmp`/dst yang punya IP statis di `172.28.0.224/27` — gateway WhatsApp diakses lewat nama
  container `whatsapp-gateway`, bukan IP tetap, jadi tidak termasuk kelas masalah IP collision yang
  didokumentasikan panjang lebar di bagian lain CLAUDE.md).
- **`./whatsapp-gateway/auth_state`** — bind-mount HOST path (bukan named volume Docker) — artinya folder
  ini benar-benar ada di filesystem host `/opt/boss-app/whatsapp-gateway/auth_state/`, bukan volume
  terkelola Docker. Ini yang membuatnya bertahan lintas `docker compose up -d --build` (rebuild image),
  tapi juga berarti dia HARUS eksplisit dicakup backup (`scripts/backup.sh`) kalau belum — perlu dicek
  terpisah, di luar scope investigasi Langkah 0 ini.

### `Dockerfile`

```dockerfile
FROM node:22-alpine
RUN apk add --no-cache git   # dibutuhkan npm install (dependency transitif resolve dari git URL)
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install --omit=dev
COPY . .
EXPOSE 3000
CMD ["node", "index.js"]
```

### `package.json` — dependencies

```json
"@whiskeysockets/baileys": "^6.7.9",   // pinned ^6.7.x, versi live saat ini 6.7.24 (dicek npm view, stabil terbaru)
"express": "^4.19.2",
"pino": "^9.3.2",                       // structured logger
"qrcode": "^1.5.3"                      // encode string QR Baileys → base64 PNG
```

### `.env` yang relevan (root `.env`/`.env.example` DAN `whatsapp-gateway/.env`/`.env.example`)

| Variabel | Dibaca oleh | Nilai contoh |
|---|---|---|
| `WHATSAPP_GATEWAY_URL` | Laravel (`config('services.whatsapp_gateway.url')`) | `http://whatsapp-gateway:3000` |
| `WHATSAPP_GATEWAY_HMAC_SECRET` | KEDUANYA (harus identik byte-untuk-byte) | (secret acak, infra-level) |
| `PORT` | Node (`whatsapp-gateway/.env`) | `3000` |
| `LARAVEL_BASE_URL` | Node — tujuan webhook `notifySessionStatus()` | `http://boss-nginx` (bukan `boss-app` langsung — lewat nginx) |

---

## Ringkasan untuk Tahap Perencanaan Berikutnya (BUKAN bagian dari Langkah 0 — hanya daftar pertanyaan terbuka)

Investigasi ini SENGAJA berhenti sebelum masuk desain/implementasi Go, sesuai instruksi. Poin-poin di atas
yang masih perlu keputusan eksplisit di tahap berikutnya (bukan untuk dijawab sekarang):

1. Skema penyimpanan whatsmeow — tabel baru di `boss_db` vs database Postgres baru di container
   `boss-postgresql` yang sama (lihat bagian 3).
2. Semua sesi aktif (reseller manapun + "direct") harus di-scan/pairing ulang saat cutover — tidak ada
   migrasi otomatis kredensial dari format Baileys.
3. Pemetaan kode/alasan disconnect Baileys (`DisconnectReason` enum) ke event whatsmeow
   (`events.LoggedOut.Reason`, `events.ConnectFailureReason`, dll) — TIDAK identik 1:1, perlu tabel
   pemetaan eksplisit sebelum reconnect/backoff logic dipindah.
4. Opsi `markOnlineOnConnect`/`syncFullHistory` (atau setaranya) di whatsmeow perlu diverifikasi
   defaultnya masing-masing, tidak diasumsikan sama dengan Baileys.
5. Strategi rollout: bangun service Go paralel dulu (container baru, belum menggantikan
   `whatsapp-gateway` yang lama) → uji menyeluruh (termasuk retest race-condition `connectLocks`,
   normalisasi nomor JID, webhook HMAC) → baru cutover per-sesi atau sekaligus — bukan "ganti Dockerfile
   lalu deploy" langsung tanpa jaring pengaman.
