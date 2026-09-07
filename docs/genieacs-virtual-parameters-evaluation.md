# Evaluasi GenieACS Virtual Parameters vs. Pendekatan BOSS App

**Tanggal**: 2026-09-02
**Konteks**: rekan Agung menjalankan GenieACS dengan 19 **Virtual Parameters** (VP) —
script JS server-side yang mencoba banyak path TR-069 beda-beda vendor lalu menormalisasi
jadi satu nama parameter konsisten. File referensi lengkap dengan script asli:
`virtualParameters-2026-09-02T021621338Z.csv` (di root repo, tidak di-commit — binary/dump).
**Status**: MURNI EVALUASI. Tidak ada VP yang diimplementasikan di sprint ini. Keputusan
eksekusi menyusul terpisah kalau memang direkomendasikan.

---

## Tiga lapisan yang sedang dibandingkan

| Lapisan | Apa yang dilakukan | Di mana jalan | Biaya per pemanggilan |
|---|---|---|---|
| **A. Provision `declare()`** (`docker/genieacs/presets/default.js` / `default-optical.js` / `default-pppoe.js`) | Refresh terjadwal path RAW vendor ke pohon tersimpan (hourly / per-menit) | GenieACS CWMP, saat setiap perangkat Inform | ~gratis (dibatch ke sesi Inform yang memang sudah terjadi), tapi menambah commit iteration |
| **B. Resolver PHP** (`App\Services\Network\CpeParameterResolverService`) | Baca pohon TERSIMPAN, pilih instance/path vendor yang benar, konversi | boss-app, saat load halaman Detail CPE / DataTable / sync terjadwal | 1 GET NBI per perangkat (yang memang sudah terjadi) |
| **C. GenieACS Virtual Parameters** | Script JS server-side: coba banyak path kandidat (tiap `declare()` memicu fetch perangkat kalau stale), normalisasi ke 1 nama | GenieACS NBI, saat `VirtualParameters.X` di-query | N `declare()` = potensi N round-trip ke perangkat per pemanggilan |

---

## Analisis per Virtual Parameter (19)

| VP | Fungsi | Sudah tercakup A+B? | Rekomendasi |
|---|---|---|---|
| **RXPower** | RX optik, 9 objek vendor, konversi log10 per-vendor + passthrough negatif | **YA** — `default-optical.js` (2026-09-02) mendeklarasikan semua objek + TXPower; `CpeParameterResolverService::resolveOpticalDbm()` memport LOGIKA per-vendor (negatif = sudah dBm, positif = `10*log10(raw*1e-4)`, 0 = tak ada sinyal) | **TIDAK PERLU sebagai VP.** Logika sudah diport ke A+B. |
| **pppoeUsername / pppoeUsername2** | Username PPPoE, 10-12 kombinasi indeks, skip mode bridge | **SEBAGIAN** — `default-pppoe.js` refresh nilai; `resolvePppoeConnection()` walk generik. **Skip bridge (`PPPoE_Bridged`) diport** (2026-09-02, `isBridgedConnection()`) | **TIDAK PERLU sebagai VP.** |
| **pppoeIP** | IP eksternal PPPoE, rantai fallback, skip bridge | **YA** (setelah `default-pppoe.js` + resolver) | **TIDAK PERLU sebagai VP.** Bisa ditambah ke `resolvePppoeConnection()` sebagai field kalau mau ditampilkan (belum, scope kecil). |
| **pppoeMac** | MAC PPPoE, 4 kombinasi indeks | **YA** — `resolveMacFromDevice()` (2026-09-02) walk WANPPPConnection→WANIPConnection→LAN | **TIDAK PERLU sebagai VP.** |
| **pppoePassword** | Password PPPoE (writable) | **YA** — `resolvePppoeConnection()` return `password` (reveal on-demand, `CpeDeviceDetailController::pppoePassword()`) | **TIDAK PERLU sebagai VP.** |
| **PonMac** | MAC PON (`X_CU_SerialNumber` / `LANHostConfigManagement.MACAddress` / WANPPPConnection, special-case ZIONCOM) | **SEBAGIAN** — kandidat `X_CU_SerialNumber` + `LANHostConfigManagement.MACAddress` diport ke `resolveMacFromDevice()` | **TIDAK PERLU sebagai VP.** Special-case ZIONCOM tidak diport (belum terbukti perlu di fleet ini). |
| **WlanPassword** | Kata sandi WLAN (writable, set 2 lokasi KeyPassphrase) | **YA** — `default.js` sudah declare kedua lokasi; `cpe_parameter_maps` `wifi_password` + `CpeActionService::setWifiCredentials()` untuk tulis | **TIDAK PERLU sebagai VP.** |
| **getdeviceuptime / getpppuptime** | `DeviceInfo.UpTime` / `WANPPPConnection.Uptime` diformat `"Nd HH:MM:SS"` | **YA (data)** — `device_uptime_seconds` return detik mentah; format di UI | **TIDAK PERLU.** Formatting milik UI, bukan ACS. |
| **gettemp** | Suhu transceiver, 8 objek vendor, **konversi regresi-linear dari sampel** | Tidak — belum ada key `temperature` di resolver | **TIDAK REKOMENDASI adopsi VP.** Konversi regresi (`tr069Values=[11509,...]` fit ke `[45,...]`) rapuh & spesifik sampel. CLAUDE.md "RX Power History" sudah menetapkan pendekatan SFF-8472 yang lebih bersih untuk suhu ZTE. Kalau suhu mau ditampilkan: pakai `sff8472` yang sudah terdokumentasi, scope baru. |
| **activedevices** | Jumlah klien tersambung di semua SSID (sum `TotalAssociations`) | Tidak — tapi ada yang **lebih baik**: `cpe_connected_hosts` (v0.7.6) per-host | **TIDAK PERLU.** `cpe_connected_hosts` lebih detail. |
| **getSerialNumber** | Decode serial hex→text, special-case EPON/ZIONCOM | Tidak | **TIDAK PERLU untuk fleet ini** — semua perangkat sudah muncul di GenieACS dengan serial terbaca (ZICG…, CMDC…, …). Relevan hanya kalau ada vendor yang melaporkan serial hex-encoded (belum terlihat). |
| **getponmode** | EPON/GPON/Ethernet dari `WANAccessType` | Tidak — BOSS App belum menampilkan mode PON | **OPSIONAL, prioritas rendah.** Scope BARU (bukan mengisi field kosong yang ada). Kalau mau: 1 `declare()` + 1 helper resolver. |
| **IPTR069** | IP manajemen TR-069 perangkat | Tidak — bukan field tampilan Detail CPE (dipakai internal Connection Request) | **TIDAK PERLU untuk task ini.** |
| **superAdmin / superPassword / userAdmin / userPassword** | Kredensial web admin ONT itu sendiri, 6-9 path vendor, writable | Tidak — BOSS App belum punya apa-apa untuk ini | **FITUR BARU BERNILAI, keputusan terpisah.** CS butuh login ke ONT pelanggan untuk troubleshoot. Daftar path multi-vendor VP = referensi bagus. Tapi ini FITUR BARU (bukan "isi field kosong yang sudah ada"), butuh: kolom penyimpanan terenkripsi + UI reveal + kebijakan RBAC (mirip PPPoE password). **Rekomendasi: backlog, sprint tersendiri.** |

---

## Kenapa TIDAK mengadopsi GenieACS-side VirtualParameters sama sekali

1. **Beban perangkat.** Tiap pemanggilan VP menjalankan N `declare(path, {value: Date.now()})` →
   GenieACS memaksa fetch tiap path dari perangkat kalau stale. Untuk halaman DataTable yang
   menampilkan beberapa VP di seluruh fleet, itu **burst connection-request / getParameterValues**
   — persis lawan dari yang kita mau, mengingat sejarah `too_many_commits`. Pendekatan
   provision-terjadwal (Lapisan A) menyebar beban itu ke siklus Inform tiap perangkat sendiri.
2. **Dua sumber kebenaran.** VP hidup di `db.virtualParameters` GenieACS → harus masuk
   `docker/genieacs/` + `apply.sh` (BOSS-001) dan dipelihara terpisah dari resolver PHP.
   Resolver PHP sudah ada, sudah dites, baca pohon tersimpan tanpa beban perangkat ekstra.
3. **Logika VP tetap dipakai — sebagai REFERENSI, bukan kode.** Daftar path per-vendor +
   rumus konversi VP `RXPower`/`pppoeMac`/`pppoeUsername` diport ke `CpeParameterResolverService`
   + `default-optical.js`/`default-pppoe.js`. Satu sumber kebenaran (PHP + provision terjadwal).

---

## Kesimpulan: ADOPSI SEBAGIAN (logika, bukan VP GenieACS)

**Sudah dikerjakan (branch `fix-genieacs-pppoe-provision`, 2026-09-02):**

1. **RX/TX Power** → `default-optical.js` (daftar objek vendor penuh + TXPower untuk semua) +
   `resolveOpticalDbm()` (konversi per-vendor). + fallback `cpe_parameter_maps` per-`product_class`
   (bukan cuma exact-OUI) di `mapsFor()`. → RX/TX terisi untuk ~99% fleet (datanya sudah ada di
   pohon, hanya butuh refresh + fallback resolver).
2. **PPPoE Username/IP/MAC** → `default-pppoe.js` (refresh leaf) + `resolveMacFromDevice()`
   (rantai WANPPPConnection→WANIPConnection→LAN) + `isBridgedConnection()` (skip WAN bridge).
3. **Uptime** → fallback `DeviceInfo.UpTime` / `Device.DeviceInfo.UpTime` di resolver.

**TIDAK diadopsi** (redundan / lebih baik di tempat lain): `getdeviceuptime`/`getpppuptime`
(formatting → UI), `activedevices` (`cpe_connected_hosts` lebih baik), `gettemp` (regresi rapuh),
`getSerialNumber`/`getponmode`/`IPTR069` (tak perlu / scope baru).

**FITUR BARU untuk backlog (keputusan terpisah, sprint tersendiri):**
`superAdmin`/`superPassword`/`userAdmin`/`userPassword` — retrieval kredensial web-admin ONT
untuk dukungan CS. Daftar path multi-vendor VP jadi referensi. Butuh kolom terenkripsi + UI
reveal + RBAC (pola PPPoE password / Xendit secret).
