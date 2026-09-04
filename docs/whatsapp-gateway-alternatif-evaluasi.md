# Evaluasi Alternatif WhatsApp Gateway — Baileys custom vs WAHA

Dibuat: sprint `whatsapp-gateway-reliability`, 2026-09-04. Murni dokumen
evaluasi tertulis — **TIDAK ADA migrasi/implementasi WAHA yang dikerjakan
di sprint ini**, sesuai instruksi eksplisit.

## Konteks: kenapa evaluasi ini diminta

Agung melaporkan `whatsapp-gateway` (Baileys custom, v0.4.0) sering putus
koneksi, scan QR lambat, dan sering perlu refresh berkali-kali untuk
connect. Dua referensi disebut sebagai pembanding: **Weblas** (stabil,
tidak diketahui backend-nya — tidak bisa dievaluasi lebih jauh tanpa info
lebih) dan **WAHA** (`whatsapp-http-api`, open-source, aktif dimaintain).

Root cause NYATA dari masalah stabilitas yang dilaporkan sudah ditemukan
dan diperbaiki di sprint yang sama (lihat CLAUDE.md bagian
"WhatsApp Gateway Reliability" untuk detail lengkap) — sebuah race
condition di `sessionManager.js` sendiri, bukan keterbatasan Baileys.
Evaluasi WAHA di bawah ini tetap dikerjakan sesuai permintaan, sebagai
pembanding strategis jangka panjang, terlepas dari fix yang sudah
dilakukan.

## Apa itu WAHA

[WAHA](https://github.com/devlikeapro/waha) adalah REST API wrapper —
bukan library WhatsApp sendiri — di atas beberapa "engine" komunitas
yang berbeda-beda pendekatannya:

| Engine | Cara kerja | Bahasa/library dasar |
|---|---|---|
| **WEBJS** / **WPP** | Menjalankan browser Chromium sungguhan (Puppeteer) yang login ke web.whatsapp.com, seperti manusia buka WhatsApp Web | Node.js + Puppeteer |
| **NOWEB** | Reimplementasi protokol WhatsApp Web dari nol lewat WebSocket langsung, TANPA browser | Node.js/TypeScript — **pendekatan yang SAMA PERSIS dengan Baileys** (reverse-engineered, bukan API resmi) |
| **GOWS** | Reimplementasi protokol WhatsApp Web dari nol lewat WebSocket langsung, ditulis Go | Go, dibangun di atas [`whatsmeow`](https://github.com/tulir/whatsmeow) — **library BERBEDA dari Baileys sepenuhnya** |

**Lisensi**: sejak versi `2026.6.1`, WAHA sepenuhnya gratis — tier
Core/Plus/PRO berbayar sudah dihapus, semua fitur ada di image publik.
Ini mengoreksi asumsi lama bahwa WAHA adalah produk berbayar.

## Temuan kunci — dikonfirmasi dari sumber nyata (GitHub issues WAHA
sendiri, bukan asumsi)

### 1. NOWEB (engine JS WAHA) berbagi kelas masalah yang SAMA dengan Baileys

Dari diskusi komunitas: *"protokol WhatsApp Web membawa permukaan
instabilitas yang sudah dikenal luas — badai koneksi terputus status 408
berulang. Baileys kemungkinan besar tidak akan pernah benar-benar stabil
selama ia tetap reverse-engineered."* Pernyataan ini berlaku SAMA
untuk NOWEB — keduanya adalah reimplementasi protokol yang sama dari
nol, di bahasa yang sama (Node.js), lewat cara yang sama (WebSocket
langsung tanpa browser). **Log riil `whatsapp-gateway` kita sendiri
menunjukkan persis pola ini** — `statusCode 408` ("Timed Out") berulang
pada `fetchProps`/`init queries` — bukti langsung bahwa migrasi ke NOWEB
TIDAK akan menyelesaikan kelas masalah ini, hanya memindahkannya ke
codebase yang berbeda.

### 2. GOWS (whatsmeow) dilaporkan lebih stabil — tapi itu library yang BERBEDA, bukan "WAHA vs Baileys"

GOWS digambarkan komunitas sebagai *"engine baru, cepat, sangat andal
dan stabil, dimaksudkan sebagai pengganti NOWEB di masa depan."*
Laporan pengguna nyata: setelah pindah ke whatsmeow, masalah
"auto-logout" mereda, sesi tetap stabil berminggu-minggu selama
penyimpanan (store) persisten dijaga, dengan footprint resource yang
dapat diprediksi dan tanpa kebocoran memori pada uptime panjang.

**Implikasi penting**: kalau WAHA dipertimbangkan, **HARUS engine GOWS**,
bukan NOWEB — memilih NOWEB semata-mata untuk "ganti dari Baileys" tidak
akan memperbaiki stabilitas sama sekali, karena akar masalahnya
(protokol reverse-engineered di Node.js) identik.

### 3. RISIKO NYATA DAN TERKONFIRMASI — kehilangan sesi massal saat redeploy/upgrade

Ini persis kekhawatiran yang Agung sebutkan sendiri, dan ternyata
**bukan cuma persepsi — ada laporan resmi di GitHub WAHA**:

> **Issue [#1591](https://github.com/devlikeapro/waha/issues/1591)**
> "Redeploying WAHA deletes all sessions and configurations" — setiap
> kali container di-redeploy/diupdate, SEMUA sesi WhatsApp dan
> konfigurasi terhapus, memaksa scan ulang SEMUA QR code dan konfigurasi
> ulang dari nol. Pelapor menyebut ini membuat WAHA *"tidak stabil untuk
> pemakaian produksi"* justru karena redeploy/maintenance rutin memicu
> kehilangan data total.
>
> **Status issue ini: CLOSED sebagai "not planned"** — maintainer TIDAK
> menerimanya sebagai bug yang perlu diperbaiki. Ini bukan bug langka
> yang sudah di-patch — ini keterbatasan yang diterima begitu saja oleh
> tim WAHA sendiri.

Laporan lain yang senada ditemukan (judul-judul, belum ditelusuri detail
satu-satu — cukup untuk menunjukkan ini POLA, bukan insiden tunggal):
- `#1600` "[GOWS] - After upgrade to latest version my WAHA stop working"
- `#1186` "[WEBJS] - Default session not connecting after upgrading to
  latest release"
- `#1456` "[gows] - Webhooks stop sending until session reconnect"

**Relevansi langsung untuk BOSS App**: seluruh riwayat sprint di
CLAUDE.md menunjukkan container di deployment ini di-rebuild/di-recreate
SANGAT SERING selama pengembangan aktif (setiap perbaikan
`Dockerfile`/`entrypoint.sh` di modul manapun). Kalau `whatsapp-gateway`
diganti WAHA dengan risiko "redeploy = semua sesi hilang" yang
dikonfirmasi maintainer-nya sendiri sebagai perilaku yang tidak akan
diperbaiki, ini adalah risiko operasional yang JAUH LEBIH BESAR
dibanding pola disconnect/reconnect yang sedang diperbaiki di Baileys
custom kita — yang mempertahankan `auth_state` persisten lewat restart
container tanpa masalah (sudah terbukti sejak v0.4.0, "auth state
survives container restarts").

## Kompleksitas migrasi — API surface yang perlu diubah

Kalau WAHA (misal engine GOWS) tetap dipertimbangkan suatu saat, area
kode yang PASTI perlu diubah di sisi Laravel:

| Area | Baileys custom (sekarang) | WAHA |
|---|---|---|
| Autentikasi request | HMAC-SHA256 kustom (`WhatsappHmac`, timestamp+body) | API key statis (header `X-Api-Key`) — model keamanan LEBIH LEMAH dari yang sudah dibangun, perlu keputusan sendiri |
| Endpoint kirim pesan | `POST /sessions/{key}/send` (kontrak sendiri) | `POST /api/sendText` (kontrak WAHA, beda shape body sepenuhnya) |
| Multi-sesi per reseller | Native (`session_key` = reseller_id atau "direct", 1 folder auth_state per key) | WAHA punya konsep multi-sesi juga, tapi mapping ke `session_key` kita perlu dipetakan ulang |
| Webhook status sesi | `connection.update` → `notifySessionStatus()` kustom kita | WAHA punya webhook sendiri (shape event berbeda total) — `WhatsappSessionService::updateStatusFromWebhook()` perlu ditulis ulang |
| QR code | Baileys `qr` event → data URL | WAHA expose endpoint QR sendiri (shape berbeda) |
| Kode Pairing | `sock.requestPairingCode()` (baru dibangun sprint ini) | WAHA juga mendukung ini di engine yang sesuai, tapi endpoint/API beda |
| Auth state persistence | Folder `auth_state/{key}/` per sesi, bind-mount volume Docker (SUDAH TERBUKTI bertahan lintas restart) | Tergantung engine + database (WAHA butuh PostgreSQL/lainnya untuk beberapa fitur di versi 2025.1+) — infrastruktur TAMBAHAN, bukan sekadar ganti container |

Estimasi: ini BUKAN migrasi kecil — **seluruh modul
`App\Services\Whatsapp\*` + `App\Jobs\SendWhatsappMessageJob` +
`whatsapp-gateway/` container perlu ditulis ulang**, bukan sekadar ganti
satu file konfigurasi. Skala perubahan sebanding dengan membangun ulang
v0.4.0 dari nol untuk backend yang berbeda.

## Rekomendasi

**TETAP di Baileys custom, dengan perbaikan Langkah 1-2 yang sudah
dikerjakan di sprint ini** (fix race condition `device_removed` + opsi
Kode Pairing) — bukan pindah ke WAHA sekarang. Alasan:

1. **Root cause nyata dari keluhan Agung SUDAH ditemukan dan
   diperbaiki** di sprint yang sama (race condition `connect()`, bukan
   keterbatasan struktural Baileys) — belum ada bukti bahwa migrasi
   backend diperlukan sama sekali untuk menyelesaikan masalah yang
   dilaporkan.
2. **Risiko WAHA yang paling relevan buat Agung (kehilangan sesi massal
   saat upgrade) TERKONFIRMASI NYATA dan TIDAK AKAN DIPERBAIKI** oleh
   maintainer WAHA sendiri (issue ditutup "not planned") — ini justru
   masalah yang LEBIH BESAR dari yang sedang kita perbaiki, bukan lebih
   kecil.
3. **Engine yang genuinely lebih stabil dari Baileys (GOWS/whatsmeow)
   berarti library BERBEDA sepenuhnya dari yang WAHA-nya sendiri jadikan
   default (NOWEB)** — memilih WAHA tanpa secara eksplisit memilih GOWS
   tidak memberi keuntungan stabilitas apa pun, karena NOWEB berbagi
   kelas masalah yang sama dengan Baileys.
4. **Biaya migrasi (seluruh `App\Services\Whatsapp\*` ditulis ulang, +
   infrastruktur database tambahan untuk WAHA) tidak proporsional**
   dibanding manfaat yang belum terbukti dibutuhkan.

**Kapan WAHA (engine GOWS) layak dicoba ulang**: kalau, SETELAH fix
race-condition di sprint ini berjalan beberapa minggu, pola
disconnect/`device_removed` TETAP muncul dengan frekuensi yang
mengganggu operasional (bukan cuma catatan log) — barulah patut
dicoba **di environment testing terpisah dulu** (Docker Compose
terpisah, sesi WhatsApp nomor test, bukan nomor produksi), secara
eksplisit dengan engine GOWS, dan HANYA dengan strategi mitigasi
eksplisit untuk risiko #1591 (mis. backup manual `auth_state`-equivalent
WAHA sebelum setiap redeploy, kalau memang bisa — perlu diriset
terpisah kalau saat itu tiba) — bukan diadopsi langsung ke produksi.

## Sumber

- [devlikeapro/waha — repo utama](https://github.com/devlikeapro/waha)
- [WAHA — dokumentasi resmi](https://waha.devlike.pro/)
- [Issue #1591 — Redeploying WAHA deletes all sessions and configurations (closed, not planned)](https://github.com/devlikeapro/waha/issues/1591)
- [Issue #1600 — [GOWS] After upgrade to latest version my WAHA stop working](https://github.com/devlikeapro/waha/issues/1600)
- [Issue #1186 — [WEBJS] Default session not connecting after upgrading to latest release](https://github.com/devlikeapro/waha/issues/1186)
- [Issue #1456 — [gows] Webhooks stop sending until session reconnect](https://github.com/devlikeapro/waha/issues/1456)
- [tulir/whatsmeow discussion #979 — Whatsmeow vs Baileys stability](https://github.com/tulir/whatsmeow/discussions/979)
- [WAHA 2025.1 changelog — PostgreSQL support, GOWS engine](https://dev.to/waha/waha-20251-postgresql-support-gows-engine-and-more-4njk)
