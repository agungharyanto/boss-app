# ZTE ZXA10 C300 — CLI Reference

**Status: DRAFT, belum di-commit.** Disusun dari sesi CLI read-only via Telnet (v0.23.2 ZTE C300
research, 2026-09-21) ke `10.168.100.34:23` (registry BOSS App: `olt_devices` id=1, nama
`c300.kaliwungu.bajastu.id`, model C300). **Tidak ada data pelanggan/MAC/SN/password/SNMP community di
dokumen ini** — semua contoh di bawah SINTETIS atau murni struktural, bukan nilai asli yang sempat
terlihat selama sesi. Output mentah (kalau ada dump besar) sempat ditulis sementara di dalam container
Docker sekali-pakai, dihapus dari sana segera setelah ekstraksi struktural, tidak pernah masuk repo/host.

## Sumber

- **Akses**: Telnet (bukan SSH — beda dari kedua HSGQ), port 23, kredensial dari `olt_devices` id=1,
  tidak pernah dicetak/dikutip/disimpan.
- **Sesi 1 (Tahap A, 2026-09-21)**: berakhir TIDAK BERSIH karena insiden nyata — lihat "Insiden Sesi 1"
  di bawah. Login/mode/prompt awal berhasil terkonfirmasi sebelum insiden terjadi.

## Alur login & mode (TERUJI, Sesi 1)

```
$ telnet 10.168.100.34 23
Username: ****
Password: ****
% The password is not strong, please change the password.
c300.kaliwungu.bajastu.id#
```

- **Login langsung mendarat di prompt PRIVILEGED** (`c300.kaliwungu.bajastu.id#`, diakhiri `#`) —
  **BEDA dari kedua HSGQ** (yang mendarat di mode VIEW `>` dan butuh `enable` terpisah tanpa password
  tambahan). Di ZTE C300 ini, **`enable` TIDAK DIPERLUKAN untuk masuk mode privileged** — sudah privileged
  sejak login.
- **Device sendiri menampilkan peringatan keamanan**: *"The password is not strong, please change the
  password."* — konfirmasi LANGSUNG dari device (bukan asumsi kami) bahwa kredensial ini perlu diganti —
  lihat `docs/omci/backlog-security.md`.
- **`enable` (dicoba tanpa sadar perlu, sebagai bagian discovery) TERNYATA meminta password TERPISAH**
  yang tidak kami miliki (`olt_devices` cuma menyimpan satu `telnet_password`, tidak ada kolom "enable
  password" terpisah) — **lihat "Insiden Sesi 1" di bawah**. Untuk sesi berikutnya: **JANGAN kirim
  `enable`** — device sudah privileged sejak login, mengirim `enable` cuma memicu prompt password yang
  tidak bisa dijawab.

## ⚠️ Insiden Sesi 1 — dilaporkan penuh, tanpa disembunyikan

Directive `enable` (dikirim sebagai bagian discovery Tahap A, TIDAK tahu bahwa device sudah privileged
sejak login) memicu prompt `Password:` KEDUA yang tidak kami punya jawabannya — **terdeteksi dengan
benar oleh mekanisme keamanan skrip, TIDAK PERNAH dijawab/ditebak**. Sesi kemudian di-set `had_error=1`
dan masuk ke urutan logout skrip.

**Bug nyata di urutan logout**: skrip riset (versi SEBELUM diperbaiki) mengirim `exit`/`exit`/`logout`
secara membabi buta untuk menutup sesi — TIDAK menyadari bahwa device MASIH menunggu di prompt
`Password:` (sisa dari percobaan `enable` yang gagal di atas). Device menerima ketiga baris teks itu
**SEBAGAI 3 PERCOBAAN PASSWORD SALAH** (`%Error 20208: Bad password` × 3) sebelum akhirnya device sendiri
membatalkan sub-dialog tersebut dan kembali ke prompt normal. Sesi kemudian ditutup lewat teardown
container (`docker run --rm`), BUKAN logout aplikasi yang bersih.

**Dampak nyata**: 3 percobaan "password" (isinya teks `exit`/`exit`/`logout`, BUKAN password asli — tidak
ada kredensial asli yang bocor/salah kirim) tercatat gagal di sisi device untuk sub-dialog `enable`. Tidak
diketahui apakah device ini punya mekanisme lockout-setelah-N-percobaan-gagal untuk sub-dialog `enable`
secara spesifik — **[TIDAK BISA DIVERIFIKASI]**, perlu diwaspadai kalau `enable` dicoba lagi nanti (dengan
password yang benar, kalau/ketika ditemukan).

**Perbaikan langsung diterapkan** (sebelum sesi berikutnya): urutan logout sekarang mengirim Ctrl-C
(`\x03`) LEBIH DULU, tanpa syarat, sebelum mengirim teks `exit`/`logout` apa pun — supaya sub-dialog yang
tersangkut (password atau konfirmasi apa pun) dibatalkan bersih tanpa pernah disalahartikan sebagai
input lagi. **Pelajaran untuk sesi ZTE berikutnya dan OLT lain di masa depan**: jangan pernah mengasumsikan
`enable` diperlukan tanpa mengonfirmasi mode/prompt awal dulu.

**Sesuai aturan jeda (sesi berakhir tidak bersih) — sesi berikutnya menunggu minimal 5 menit** dari
selesainya sesi ini sebelum dicoba lagi.

## Command mode `#` (bare `?`, TERUJI Sesi 2)

38 command (Exec mode) — jauh lebih banyak dari kedua HSGQ, device multi-service (bukan cuma GPON/EPON):
`auto-update`, `bfd-stat`, `cfm`, `check`, `clear`, `clock`, `configure` (masuk config — **denylist**),
`debug` (**denylist**), `diagnose`, `disable`, `enable`, `end`, `exit`, `file`, `kick-off`, `license`,
`login`, `logout`, `no` (**denylist**), `package`, `patch`, `ping`, `ping6`, `qry`, `quit`, `release`,
`remote-unit`, `renew`, `restore`, `show`, `telnet`, `telnet6`, `terminal`, `trace`, `trace6`, `user`,
`who`.

## `terminal ?` (TERUJI Sesi 2)

| Sub-command | Fungsi |
|---|---|
| `length` | Set telnet length of the display terminal |
| `monitor` | Copy debug output to the current terminal line |

Pola sama seperti G02ID (`length`/`monitor`) — belum dieksekusi, hanya bantuan.

## `show ?` (TERUJI Sesi 2 — daftar SANGAT panjang, device multi-service penuh: xDSL/ATM/L2VPN/MPLS/dst, bukan cuma GPON/EPON)

**~230 sub-command** ditemukan (device chassis multi-layanan). Yang relevan OMCI/ONU:

| Sub-command | Fungsi |
|---|---|
| `gpon` | Show GPON information (drill-down lengkap di bawah) |
| `epon` | Show EPON config information — **terpisah dari `gpon`**, konsisten dengan `olt_devices.oltModel.supported_pon_type = gpon_epon` (device ini genuinely dukung KEDUANYA) |
| `onu` | Show **EPON** ONU information (bare `onu` = EPON-spesifik; ONU GPON ada di bawah `gpon onu`, lihat drill-down) |
| `remote` | Show EPON **remote** ONU information |
| `olt` | Show the information of OLT |
| `card` / `subcard` / `backboard` / `rack` / `shelf` | Hierarki fisik chassis — konfirmasi ADA `rackno`/`shelfno`/`slotno` sebagai parameter nyata (lihat drill-down `show card ?`) |
| `running-config` | **Current operating configuration — SELURUH config, bukan per-interface.** DILARANG dieksekusi bare (TAHAP B minta per-interface saja) |
| `interface` | Display interface property and statistics |
| `onu-type` / `onu-type-if` | ONU type template information |
| `gpon-onu-typed` | Show gpon onu typed group information |

**Drill-down level-2 TERUJI (sebelum insiden, lihat "Insiden Sesi 2" di bawah)**:

| Command | Argumen |
|---|---|
| `show card ?` | `rackno` / `shelfno` / `slotno` / `type` / `<cr>` — **konfirmasi rack/shelf/slot sebagai skema penomoran nyata**, default "rak/shelf utama" kalau tak disebutkan |
| `show backboard ?` | `rackno` / `shelfno` / `<cr>` |
| `show card-power ?` / `show card-temperature ?` | `rackno` / `shelfno` / `slotno` / `<cr>` |
| `show auto-backup ?` | `condition` / `period` / `progress` |
| `show auto-update ?` | `check-period` / `check-result` / `configure` / `progress` |
| `show control-panel ?` | `anti-dos` / `capture` / `configure` / `cpu` / `packet` / `packet-limit` |
| **`show gpon ?`** | **`global` / `loid` / `loid-mode` / `mop` / `olt` / `onu` (Show GPON ONU information) / `password-encrypt` / `profile` (Show GPON ONU profile configuration information) / `register-check` / `remote-onu` / `slot` / `sn`** — **inilah gerbang ke daftar ONU GPON & profil, TERPOTONG oleh insiden persis di titik ini** |

## ⚠️ Insiden Sesi 2 — dilaporkan penuh

**Sesi TERPUTUS TEPAT SAAT hampir mencapai command yang dicari** (`show gpon onu`/`show gpon profile`) —
bukan karena masalah device, murni bug di skrip riset sendiri:

1. **False-positive deteksi "password"**: pola deteksi prompt password di skrip (`-nocase "password"`,
   TANPA titik dua) salah memicu pada teks bantuan NORMAL — baris `sn   Show GPON card sn and
   **password** information` (deskripsi command biasa, bukan prompt sungguhan) dianggap sebagai prompt
   password tak terduga. Sesi otomatis di-abort sesuai desain keamanan (benar secara PRINSIP — tidak
   pernah menebak/menjawab — tapi SALAH secara DETEKSI). **Diperbaiki**: pola diseragamkan jadi
   `"password:"` (WAJIB titik dua) di semua 4 titik deteksi, sama seperti skrip SSH yang sudah terbukti
   aman sepanjang v0.23.1.
2. **Konsekuensi**: karena abort terjadi di tengah, baris perintah setengah-ketik (`show gpon `) belum
   sempat dibersihkan (Ctrl-U cuma dikirim di jalur sukses, bukan jalur error) — **diperbaiki**: jalur
   error sekarang juga mengirim Ctrl-U sebelum keluar.
3. **Mekanik logout ZTE (DIKOREKSI Agung, BUKAN temuan "config belum tersimpan")**: urutan logout
   (`exit`) memicu prompt: *"The configuration is changed,confirm to logout without saving?
   [yes/no]:"*. **Prompt ini SELALU muncul di terminal ZTE saat logout, walau sesi hanya baca — bukan
   indikasi ada perubahan nyata yang belum tersimpan.** Versi awal skrip (SEBELUM diperbaiki) tidak
   mengenali pola ini secara eksplisit — `exit` KEDUA sempat mendarat sebagai jawaban SAMPAH ke prompt
   tersebut (device menolaknya, `%Error 20205: Invalid input detected`, nol aksi tereksekusi), sesi
   akhirnya tertutup lewat teardown koneksi, bukan logout aplikasi yang bersih.
4. **Diperbaiki (versi final)**: urutan logout sekarang MENGENALI teks PERSIS
   `"configuration is changed, confirm to logout without saving? [yes/no]"` dan mengirim **`yes`** —
   satu-satunya pengecualian jawab-konfirmasi yang diizinkan (dikonfirmasi eksplisit oleh Agung, karena
   prompt ini murni mekanik logout ZTE, bukan konfirmasi perubahan sungguhan). Prompt konfirmasi/password
   APA PUN SELAIN teks persis ini tetap TIDAK PERNAH dijawab — kalau muncul, koneksi ditutup langsung dari
   sisi client tanpa mengirim apa pun lagi.

**Sesuai aturan jeda (sesi berakhir tidak bersih) — Sesi 3 menunggu minimal 5 menit lagi** dari
selesainya Sesi 2.

## [TIDAK BISA DIVERIFIKASI] — sebelum Sesi 3

- **`show gpon onu ?` dan `show gpon profile ?`** — persis di ambang ini insiden terjadi, BELUM
  terkirim. Kandidat PALING KUAT untuk daftar ONU unconfigure — prioritas utama Sesi 3.
- Command untuk daftar ONU unconfigure (uncfg) SPESIFIK (`show gpon onu` kemungkinan besar induknya,
  sub-argumen persis belum diketahui).
- Sintaks penamaan ONU (`name` vs `description`) + batas panjang.
- Mekanisme profil/TR069 otomatis untuk ONU baru.
- Apakah SmartOLT membuka sesi Telnet sendiri (`who`/`show users` belum dijalankan).
- Password `enable` yang benar — tidak diketahui, tidak pernah ditebak, tidak akan ditebak (tidak
  relevan lagi untuk baca-saja karena device sudah privileged sejak login — dicatat untuk arsip).

## Sesi 3 — SELESAI BERSIH (exit_code=0, 2026-09-21)

Perbaikan bug false-positive password bekerja: `show gpon ?` tidak lagi salah terdeteksi. Logout yang
memicu prompt `[yes/no]` (mekanik normal ZTE, lihat "Insiden Sesi 2" di atas) di sesi ini masih
menggunakan versi skrip yang **belum** mengirim `yes` (perbaikan itu baru ditambahkan setelahnya, lihat
Sesi 5) — sesi tetap diakhiri lewat teardown koneksi, TANPA insiden input sampah.

### `who` (TERUJI — jawaban langsung untuk pertanyaan sesi SmartOLT)

**1 sesi vty aktif** SEBELUM sesi kami: user `smartolt`, via Telnet, privilege level 5, idle,
**sumber IP internal `172.23.195.1`** (rentang tunnel WireGuard yang sudah dikenal BOSS App —
kemungkinan SmartOLT terhubung lewat jalur jaringan yang sama/berdekatan dengan yang dipakai BOSS App
sendiri, **[TIDAK 100% DIKONFIRMASI]** apakah ini genuinely lewat WireGuard yang sama atau kebetulan
rentang IP serupa). **Konfirmasi langsung: YA, SmartOLT membuka sesi Telnet sendiri ke OLT ini**,
terpisah dari sesi riset kami.

### `show gpon onu ?` — TEMUAN UTAMA, command yang dicari DITEMUKAN

```
baseinfo        Show GPON ONU basic information
by              Show GPON ONU search result
config-fail     Show GPON ONU config failed information
detail-info     Show GPON ONU detail information
distance        Show GPON ONU distance information
gemport         Show GPON ONU GEM port information
next-available  Show GPON ONU next available resource index
profile         Show GPON ONU profile information
state           Show GPON ONU state information
tcont           Show GPON ONU T-CONT information
uncfg           Show GPON unconfigured ONU information
vport           Show GPON ONU vport information
```

**`uncfg` — "Show GPON unconfigured ONU information" — INILAH command yang dicari.** Sintaks persis
(apakah butuh argumen interface PON, atau bisa bare/"all") **belum diuji** — `show gpon onu uncfg ?`
adalah langkah berikutnya.

### `show gpon profile ?` (TERUJI)

```
tcont    T-CONT profile
traffic  Traffic profile
```
Lebih sederhana dari dugaan awal — tidak ada `line-profile`/`srv-profile`/`ont-profile` di level ini
(kemungkinan berada di tempat lain, mis. `show gpon-onu-typed` atau sisi config, bukan `show gpon
profile`).

## Sesi 4 — SELESAI BERSIH (exit_code=0, 2026-09-21) — TEMUAN UTAMA, tujuan urgent tercapai

### `show gpon onu uncfg ?` (TERUJI)

```
gpon-olt_1/3  Gpon-olt interface   (opsional, nama interface spesifik)
<cr>                                (bisa dijalankan bare, tanpa argumen)
```

### `show gpon onu uncfg` (bare, TERUJI — eksekusi nyata, izin khusus dari Agung, hasil dilaporkan agregat)

**Hasil: PERSIS 2 baris** — cocok dengan jumlah yang Agung sebutkan sebelum riset ini dimulai.

**KOREKSI (Agung)**: kedua ONU ini adalah **bahan uji Agung sendiri untuk v0.23.5 (Aktivasi ZTE)** —
BUKAN pelanggan yang siap diaktifkan lewat SmartOLT. **Keduanya TIDAK PERNAH disentuh dengan command
tulis apa pun sepanjang riset ini, dan TIDAK akan disentuh sampai v0.23.5 benar-benar dikerjakan
bersama Agung.** Baris rekomendasi "boleh diaktifkan lewat SmartOLT" yang sempat dilaporkan di sesi
chat sebelumnya **sudah ditarik/diabaikan** — SmartOLT tidak lagi jadi jalur aktivasi yang disarankan
untuk ONU ini (lihat keputusan "SmartOLT dibuang penuh" di `docs/ROADMAP.md` v0.23.0).

**Kolom (nama saja, SN di-mask total, tidak pernah dikutip/disimpan)**:

| Kolom | Contoh format SINTETIS |
|---|---|
| `OnuIndex` | `gpon-onu_<rack>/<slot>/<port>:<onu-id>` — kedua baris sama-sama di `1/3/12`, beda `onu-id` (1 dan 2) |
| `Sn` | *(di-mask total — format aslinya 11-12 karakter alfanumerik uppercase, konsisten dengan pola `sn-auth` HSGQ)* |
| `State` | Kedua baris: `unknown` |

**Penomoran ONU dikonfirmasi**: `gpon-onu_<rack>/<slot atau board>/<port>:<onu-id>` — format 4 level
(rack/slot/port/onu-id), **beda dari dugaan awal TAHAP B** (`<board>/<port>` 2 level saja) — konfirmasi
`rackno`/`slotno` dari `show card ?` (Sesi 2) memang benar-benar dipakai di skema penomoran ONU
sungguhan, bukan cuma parameter query.

**Identifikasi ONU**: berdasarkan **SN** (Serial Number), bukan MAC — konsisten dengan seluruh temuan
E04ID/G02ID sebelumnya (`sn-auth`).

## Sesi 5 — LANGKAH 1: Verifikasi ONU_UJI_1/ONU_UJI_2 — SELESAI BERSIH, KEDUANYA COCOK

`who`: 1 sesi lain aktif (user `smartolt`, telnet, privilege 5, sumber `172.23.195.1`) — normal, sama
seperti temuan sebelumnya.

**`show gpon onu state gpon-olt_1/3/12`** — 3 ONU terdaftar di PON ini (`ONU Number: 2/3` = 2 online
dari 3 terdaftar):

| OnuIndex | Admin State | OMCC State | Phase State |
|---|---|---|---|
| `1/3/12:1` | enable | disable | OffLine |
| `1/3/12:2` | enable | enable | **working** |
| `1/3/12:3` | enable | enable | **working** |

**`show gpon onu baseinfo gpon-olt_1/3/12`**:

| OnuIndex | Type | AuthInfo (SN, akhiran saja) | State |
|---|---|---|---|
| `:1` | `M63X_XPON` | akhiran **beda** dari kelompok uji Agung (bukan `01E7`/`F158`) | ready |
| `:2` | `M12X5G_XPON` | akhiran **`01E7`** — **COCOK ONU_UJI_1** | ready |
| `:3` | `M12X5G_XPON` | akhiran **`F158`** — **COCOK ONU_UJI_2** | ready |

**LANGKAH 1 TERVERIFIKASI PENUH**: `gpon-onu_1/3/12:2` = ONU_UJI_1 (aktif, `working`/`ready`, tipe
`M12X5G_XPON`, akhiran SN cocok), `gpon-onu_1/3/12:3` = ONU_UJI_2 (sama). **ONU `:1` adalah ONU LAIN
yang sudah lama terdaftar** (tipe berbeda `M63X_XPON`, akhiran SN tidak cocok kelompok uji, status
offline) — **bukan bagian dari 4 ONU uji Agung, tidak disentuh sama sekali**.

## Temuan untuk desain v0.23.5 — indeks `uncfg` hanyalah USULAN, bukan slot kosong terjamin

**Dikonfirmasi ulang lewat data nyata**: `show gpon onu uncfg` (dijalankan ulang Sesi 5) tetap
menampilkan 2 ONU fresh di `OnuIndex` usulan `:1` dan `:2` — **`:2` BENTROK dengan ID yang GENUINELY
sudah terpakai** (ONU_UJI_1, dari `show gpon onu baseinfo`/`state` di atas). **ID yang benar-benar
terpakai di PON ini: 1, 2, 3** (dari `show gpon onu state`/`baseinfo`, bukan dari `uncfg`).

**Pola aman untuk v0.23.5 (baca-saja, TIDAK dieksekusi tulis)**: SEBELUM menjalankan `onu <id> type ...
sn ...` untuk ONU baru, WAJIB baca `show gpon onu state <interface-PON>` dan/atau `show gpon onu
baseinfo <interface-PON>` dulu untuk tahu ID yang GENUINELY terpakai — **jangan pernah percaya begitu
saja `OnuIndex` yang ditampilkan `show gpon onu uncfg`**. ID kosong teraman = ID terkecil yang TIDAK
muncul di `show gpon onu state`. Kandidat resmi untuk memastikan ini: sub-command **`next-available`**
(`show gpon onu next-available` — "Show GPON ONU next available resource index", ditemukan di daftar
`show gpon onu ?` Sesi 3) — **belum dieksekusi**, rencana Sesi 6.

**Apa yang terjadi kalau `onu <id> type ... sn ...` dipakai pada ID yang sudah terisi**: **[TIDAK BISA
DIVERIFIKASI]** — tidak dicoba (dilarang), tidak ada referensi publik yang eksplisit soal perilaku
persisnya (kemungkinan besar ditolak dengan error "ID already exists" atau sejenis, mengikuti pola umum
CLI Cisco-like, tapi ini DUGAAN, bukan fakta terverifikasi).

## Sesi 6 — SEBAGIAN (exit_code=2, insiden bug ke-3) — tetap menghasilkan data nyata berguna

### `show gpon onu next-available gpon-olt_1/3/12` — DITOLAK device

```
%Error 20202: Invalid input detected at '^' marker. Invalid parameter
```
Sintaks yang dicoba (argumen interface langsung) **salah** — command ini butuh bentuk argumen lain
(kemungkinan tanpa argumen, atau format berbeda). **Belum diuji ulang lewat bantuan** — rencana Sesi 7.

### `show gpon onu detail-info gpon-onu_1/3/12:2` (ONU_UJI_1) — DATA NYATA TERTANGKAP

| Field | Nilai |
|---|---|
| Name | `Test-1` *(label uji Agung sendiri, bukan nama pelanggan)* |
| Type | `M12X5G_XPON` |
| State / Phase state | `ready` / `working` |
| Config state | `fail` *(perlu klarifikasi — bukan berarti device offline, [TIDAK BISA DIVERIFIKASI] maknanya persis)* |
| Authentication mode | `sn` |
| SN Bind | `enable with SN check` |
| Password | *(field ADA, selalu kosong untuk auth mode `sn` — ini akar penyebab insiden bug, lihat di bawah)* |
| Description | `none` |
| Vport mode | `gemport` |
| DBA Mode | `Hybrid` |
| ONU Distance | `37m` |
| Online Duration | `~110j 44m` |
| Line Profile / Service Profile | `N/A` / `N/A` |

**Konfirmasi PENTING untuk sintaks nama/desc (jawab sebagian tujuan laporan 6)**: `detail-info`
menampilkan `Name` DAN `Description` sebagai **2 field terpisah** — `Name` terisi (`Test-1`),
`Description` kosong/`none` — pola SAMA seperti G02ID (`ont setting <id> name/desc`), field independen,
tidak wajib keduanya diisi.

### ⚠️ Insiden Sesi 6 — bug ke-3 di deteksi "password", diperbaiki dengan heuristik baru

Field **`  Password:            `** (berindentasi, kosong, bagian NORMAL dari output `detail-info` untuk
ONU ber-auth-mode `sn`) **salah terdeteksi sebagai prompt password sungguhan** oleh pola deteksi lama
(yang sudah diperbaiki sekali sebelumnya untuk mewajibkan titik dua, tapi TERNYATA belum cukup — field
label device sendiri JUGA memakai format "Label:" persis seperti prompt). Sesi berhenti sebelum sempat
menjalankan sisa command yang direncanakan.

**Diperbaiki dengan heuristik baru**: pola deteksi sekarang mensyaratkan teks `password:` **TIDAK
didahului karakter spasi literal** (`(?i)(^|[^ ])password:\s*$`) — field label ZTE SELALU berindentasi
2 spasi (`"  Password:"`), sedangkan prompt login sungguhan tidak pernah berindentasi. **Diverifikasi
lewat unit test Tcl terpisah sebelum dipercaya di sesi live lagi** — 4 skenario (prompt asli setelah
newline, prompt asli di awal buffer, field label berindentasi) semuanya menghasilkan keputusan yang
benar.

## Sesi 7 — SELESAI BERSIH (exit_code=0) — SUMBER UTAMA urutan aktivasi, TEMUAN LENGKAP

### `show gpon onu next-available ?` — koreksi penting

Hanya 1 sub-opsi: `gemport` ("GEM port"). **Koreksi dari dugaan Sesi 5**: command ini untuk mencari
indeks GEM PORT berikutnya yang kosong, **BUKAN** helper "ID ONU kosong berikutnya" seperti yang
diharapkan. Untuk ID ONU aman, TETAP harus baca manual `show gpon onu state`/`baseinfo` dulu (pola yang
sudah didokumentasikan di atas).

### `show gpon onu detail-info gpon-onu_1/3/12:3` (ONU_UJI_2) — cocok pola ONU_UJI_1

Sama seperti ONU_UJI_1 (`Test-1`), ONU_UJI_2 punya `Name: Test-2`, tipe `M12X5G_XPON`, `ready`/`working`,
`Description: none`. Tambahan field yang baru terlihat (device tidak paging-abort kali ini): `FEC: none`,
`1PPS+ToD: disable`, `Auto replace: disable`, `Multicast encryption: disable`, tabel riwayat 10 slot
auth/offline (2 baris terisi, sisanya kosong — histori normal, tidak dikutip persis).

### `show running-config interface gpon-onu_1/3/12:2` dan `:3` — SINTAKS LENGKAP KONFIGURASI PER-ONU

**Bentuk generik (identitas di-mask, hanya struktur)**:
```
interface gpon-onu_1/3/12:<id>
  name <Nama>
  description <teks atau "none">
  tcont 1 profile <NamaProfilTcont>
  gemport 1 tcont 1
  gemport 1 traffic-limit downstream <NamaProfilTraffic>
  service-port 1  vport 1 user-vlan <VLAN-A> vlan <VLAN-A>
  service-port 11 vport 1 user-vlan <VLAN-B> vlan <VLAN-B>
  service-port 12 vport 1 user-vlan <VLAN-C> vlan <VLAN-C>
!
end
```

**Temuan nyata pada kedua ONU_UJI**:
- `tcont 1 profile HomeFixed-10Mbps` — **SAMA PERSIS di KEDUA ONU_UJI**, dan **NAMANYA COCOK LANGSUNG
  dengan konvensi penamaan `PppPackage`/Bandwidth Profile BOSS App sendiri** (lihat CLAUDE.md cluster
  v0.14.x) — indikasi kuat profil tcont di OLT ini SUDAH diberi nama selaras paket BOSS App, kemungkinan
  disiapkan manual oleh Agung.
- `gemport 1 traffic-limit downstream PPPoE-Remote` — SAMA di keduanya, nama profil traffic
  "PPPoE-Remote" (cocok istilah pool/NAS yang sudah dikenal di codebase BOSS App).
- **3 `service-port`, masing-masing dengan VLAN berbeda** — di ONU_UJI_1: VLAN A=10 (service-port 1),
  B=9 (service-port 11), C=172 (service-port 12). Di ONU_UJI_2: **VLAN A dan B TERTUKAR** (A=9, B=10),
  **C tetap 172 di keduanya**.
- **Variabel yang BERBEDA per pelanggan (dari perbandingan langsung 2 ONU_UJI)**: `name`, VLAN pada
  `service-port 1`/`service-port 11` (VLAN 9 vs 10 — kemungkinan salah satunya internet utama, satunya
  layanan sekunder, tertukar antar pelanggan tergantung skema penomoran VLAN pelanggan).
- **Variabel yang SAMA (kemungkinan template/tetap)**: `tcont 1 profile` (nama paket — beda ANTAR PAKET,
  tapi sama untuk 2 pelanggan paket sama), `gemport 1 traffic-limit`, `service-port 12` (VLAN 172 —
  kemungkinan layanan bersama/tetap, bukan per-pelanggan), struktur 3-service-port itu sendiri.

### `show onu running config gpon-onu_1/3/12:2` dan `:3` — sintaks Agung TERKONFIRMASI ADA, TAPI struktur TR-069 BEDA dari dugaan referensi publik

**Bentuk generik**:
```
pon-onu-mng gpon-onu_1/3/12:<id>
  flow mode 1 tag-filter vlan-filter untag-filter discard
  flow 1 pri 0 vlan <VLAN-B>
  flow 1 pri 0 vlan <VLAN-A>
  flow 1 pri 0 vlan <VLAN-C>
  gemport 1 flow 1
  switchport-bind switch_0/1 iphost 1
  switchport-bind switch_0/1 veip 1
  vlan-filter-mode iphost 1 tag-filter vlan-filter untag-filter discard
  vlan-filter iphost 1 pri 0 vlan <VLAN-A>
  security-mgmt 998 state enable mode forward ingress-type lan protocol web https
  security-mgmt 999 state enable ingress-type lan protocol ftp telnet ssh snmp tr069
!
```

**KOREKSI PENTING terhadap referensi publik yang diberikan Agung**: **TIDAK ADA satu pun baris
`tr069-mgmt ...`** (bukan `tr069-mgmt 1 state unlock`, bukan `tr069-mgmt 1 acs <url>`, bukan `tr069-mgmt
1 tag pri 0 vlan <V>`) di config nyata firmware ini. **Akses TR-069 ternyata diberikan lewat baris
GENERIK** `security-mgmt 999 ... protocol ftp telnet ssh snmp tr069` — satu allowlist protokol
manajemen yang mencakup tr069 SEKALIGUS ftp/telnet/ssh/snmp, **IDENTIK di kedua ONU_UJI** (kemungkinan
besar baris TEMPLATE/TETAP, bukan per-pelanggan — tidak ada URL ACS/kredensial spesifik per-ONU yang
terlihat di level ini sama sekali). **[TIDAK BISA DIVERIFIKASI]**: di mana URL ACS/otentikasi TR-069
sebenarnya dikonfigurasi kalau bukan di sini — kandidat: level GLOBAL (`show gpon global`/`show gpon
mop`, belum dieksplorasi) atau bawaan firmware (device "menemukan" ACS lewat DHCP Option 43, cocok
dengan pola HSGQ yang sudah dikonfirmasi memakai Option 43/URL tersimpan di level line-profile, bukan
per-ONU).
- `vlan-filter iphost 1 pri 0 vlan <VLAN-A>` — **variabel per-pelanggan** (9 vs 10, cocok temuan di atas).
- Baris `security-mgmt`/`switchport-bind`/`flow mode` — **identik di kedua ONU_UJI**, kandidat kuat
  template tetap, tidak perlu diisi ulang per-pelanggan.

### `show running-config interface gpon-olt_1/3/12` — sintaks registrasi ONU TERKONFIRMASI PENUH

```
interface gpon-olt_1/3/12
  no shutdown
  linktrap disable
  onu <id> type <TipeONU> sn <SN>
!
end
```
**Sintaks `onu <id> type <tipe> sn <SN>` dari referensi Agung TERKONFIRMASI 100% BENAR** — persis begini
bentuknya di config nyata, untuk ketiga ONU terdaftar di PON ini.

**2 tipe ONU terdaftar saat ini di PON ini**: `M63X_XPON` (1 unit — ONU lama tidak terkait, id 1) dan
`M12X5G_XPON` (2 unit — KEDUA ONU_UJI). **Tidak ada nama tipe lain** yang terlihat di config nyata PON
ini (device lain mungkin punya tipe lain, di luar cakupan sesi ini).

### Jawaban TAMBAHAN LAPORAN (a)/(b)/(c)

**(a) ID ONU terpakai & pola aman**: `1, 2, 3` semua terpakai di `gpon-olt_1/3/12` (lihat Sesi 5). Pola
aman: baca `show gpon onu state <PON>` dulu, ambil ID terkecil yang TIDAK muncul di daftar itu (bukan
dari `uncfg`, dan **bukan dari `next-available` — command itu untuk gemport, bukan ID ONU**, koreksi
temuan sebelumnya).

**(b) Tipe ONU dipakai + tersedia**: 2 tipe TERPAKAI (`M63X_XPON` ×1, `M12X5G_XPON` ×2) — dari
`show running-config interface gpon-olt_1/3/12`, bukan dari bantuan daftar-tipe (belum ditemukan
command khusus "daftar semua tipe ONU yang didukung device", di luar 2 yang sudah terpakai — kandidat
`show onu-type`/`show onu-type-if` dari `show ?` root Sesi 2, belum diuji Sesi 7).

**(c) Deteksi tipe otomatis vs manual**: **MANUAL, dikonfirmasi negatif** — `show gpon onu uncfg`
(Sesi 4/5) HANYA punya kolom `OnuIndex`/`Sn`/`State`, **TIDAK ADA kolom Type sama sekali**. Tipe HARUS
ditentukan eksplisit lewat `onu <id> type <tipe> sn <SN>` saat registrasi — OLT tidak menebaknya sendiri
dari SN/uncfg.

## Sesi 8 — SELESAI BERSIH (exit_code=0), nol insiden — item TAHAP B sisa

### Chassis & infra (non-customer, struktural)

- **`show card`**: 7 kartu — 2× `PRWH` (power, satu `NOPOWER` cadangan, satu `INSERVICE`), 1× **`GTGH`/
  `GTGHG` di slot 3** (kartu GPON — cocok `gpon-olt_1/3/12`, 16 port), 2× `SCXN` (slot 10/11, pasangan
  redundan `INSERVICE`/`STANDBY`), 2× `HUVQ` (slot 19/20).
- **`show vlan summary`**: **17 VLAN total**: `1,9-10,69,101,110-111,120,130-131,140,151,172,251,4091-
  4092,4094` — **tumpang tindih besar dengan daftar VLAN G02ID** (9,10,69,101,110,111,120,130,131,140,
  151,172,251 semua muncul di kedua device) — konsisten satu skema VLAN ISP-wide, plus beberapa VLAN
  cadangan/manajemen internal khusus ZTE (4091-4092,4094).
- `show processor`/`show version-running` — info CPU/memori/firmware per kartu, sehat, tidak relevan
  langsung untuk OMCI, tidak dirinci di sini.

### Tabel Profil Paket — GOLDMINE untuk desain v0.23.5

**`show gpon profile tcont`** (9 profil bernama) × **`show gpon profile traffic`** (9 profil, nama SAMA
PERSIS) — kombinasi keduanya, per nama profil, adalah pipeline lengkap "paket → bandwidth":

| Nama Profil | tcont MBW (kbps) | traffic SIR/PIR (kbps) | Catatan |
|---|---|---|---|
| `default` | 10000 (FBW) | 9953280 / 9953280 | profil bawaan device |
| `HomeFixed-10Mbps` | 15360 | 15360 / 15360 | **dipakai KEDUA ONU_UJI** |
| `HomeFixed-20Mbps` | 35840 | 35840 / 35840 | |
| `HomeFixed-30Mbps` | 46080 | 46080 / 46080 | |
| `HomeFixed-40Mbps` | 61440 | 61440 / 61440 | |
| `HomeFixed-50Mbps` | 122880 | 122880 / 122880 | |
| `HomeFixed-100Mbps` | 122880 *(sama dgn 50Mbps — kemungkinan batas MBW belum disesuaikan)* | 9953280 / 9953280 *(shaping nyata ada di traffic profile, bukan tcont)* | **[TIDAK BISA DIVERIFIKASI]** apakah MBW 100Mbps memang sengaja disamakan dgn 50Mbps |
| `SMARTOLT-VOIPMNG-10M` | 11264 | 10480 / 11264 | peninggalan era SmartOLT, bukan paket pelanggan biasa |
| `PPPoE-Remote` | 102400 | 102400 / 102400 | nama cocok NAS pool `PPPOE-REMOTE` BOSS App |

**Nama profil `HomeFixed-<N>Mbps` COCOK PERSIS dengan konvensi penamaan `PppPackage`/Bandwidth Profile
BOSS App** — konfirmasi kuat OLT ini sudah "siap" diselaraskan dengan skema paket BOSS App, bukan
kebetulan.

### `show gpon global`/`show gpon olt`/`show gpon mop` — DITOLAK, butuh argumen tambahan

Ketiga command ini **ADA** (dikonfirmasi lewat `show gpon ?` Sesi 3) tapi **ditolak `%Error 20203:
Incomplete command`** saat dijalankan bare — butuh argumen yang belum ditemukan (BELUM DIUJI lebih
lanjut, di luar scope sesi ini). **Kandidat lokasi konfigurasi ACS TR-069 masih belum ditemukan.**

### `show gpon register-check` — TERUJI, bare

Hasil: **`gpon register-check: disable`** — toggle global, saat ini MATI. Makna persis kaitannya dengan
auto-provisioning ONU baru **[TIDAK BISA DIVERIFIKASI]** — kandidat relevan untuk `v0.23.5`, belum
dieksplorasi lebih dalam.

### `show onu-type` — katalog bawaan firmware, BUKAN data pelanggan

**28 tipe ONU terdaftar di firmware ini** (17 EPON + ~10 GPON, dari field `PON type` per entri) — daftar
referensi bawaan, bukan hasil scan device fisik. **`M12X5G_XPON`** (dipakai KEDUA ONU_UJI) dikonfirmasi:
GPON, Max T-CONT 8, Max GEM port 32, Max switch per slot 8. `show onu-type-if` ditolak (butuh argumen,
belum diuji).

### `terminal length ?` — TERUJI, kata-kata persis dikonfirmasi

`<0-512>` — *"Number of lines on screen (0 for no pausing)"* — **kata "0 for no pausing" eksplisit
dikonfirmasi kali ini** (bukan dugaan konvensi umum seperti di E04ID). Belum dieksekusi.

### `show gpon onu by sn ?` — TERUJI, sintaks dikonfirmasi (tidak dieksekusi dengan SN nyata)

`WORD` — *"Vendor serial number (must be 12 character(s))"* — SN pencarian **wajib persis 12 karakter**.

## [TIDAK BISA DIVERIFIKASI] — SISA AKHIR, untuk decision-gate `v0.23.5`

- Argumen persis `show gpon global`/`show gpon olt`/`show gpon mop` — lokasi konfigurasi ACS TR-069
  masih belum ditemukan.
- Makna `gpon register-check: disable` terhadap auto-provisioning.
- Batas panjang `name`/`description` dari bantuan resmi (field-nya sudah dikonfirmasi ADA, batasnya
  belum).
- Perilaku persis `onu <id> ...` pada ID yang sudah terpakai — tidak dicoba, murni dugaan.
- Makna persis `Config state: fail` pada kedua ONU_UJI — konsisten di keduanya, kemungkinan status
  normal untuk tipe ONU ini, bukan dikonfirmasi.
- `show onu-type-if` (argumen) — belum diuji.

## Tabel Ringkasan Command

| Command | Fungsi | Read/Write | Status |
|---|---|---|---|
| `who` | Daftar sesi vty aktif | Read | TERUJI |
| `show gpon onu state <if>` | Status ONU per PON (Admin/OMCC/Phase State) | Read | TERUJI |
| `show gpon onu baseinfo <if>` | Tipe + auth + status ringkas per ONU | Read | TERUJI |
| `show gpon onu uncfg [if]` | Daftar ONU terdeteksi belum dikonfigurasi (indeks USULAN, bukan pasti kosong) | Read | TERUJI |
| `show gpon onu detail-info <onu>` | Detail penuh 1 ONU (nama, tipe, state, SN, dll, bisa panjang/paging) | Read | TERUJI |
| `show gpon onu next-available gemport` | Indeks gemport kosong berikutnya (BUKAN untuk ID ONU) | Read | TERUJI (koreksi fungsi) |
| `show gpon onu by sn <SN 12-char>` | Cari ONU by SN | Read | DARI BANTUAN (sintaks saja, tidak dieksekusi) |
| `show running-config interface <onu>` | Config service ONU (name/desc/tcont/gemport/service-port/VLAN) | Read | TERUJI |
| `show onu running config <onu>` | Config `pon-onu-mng` (flow/vlan-filter/switchport-bind/security-mgmt) | Read | TERUJI |
| `show running-config interface <gpon-olt>` | Daftar registrasi `onu <id> type <tipe> sn <SN>` per PON | Read | TERUJI |
| `show card` | Inventaris kartu chassis | Read | TERUJI |
| `show version-running` | Versi firmware per kartu | Read | TERUJI |
| `show vlan summary` | Daftar semua VLAN dikenal device | Read | TERUJI |
| `show processor` | CPU/memori per kartu | Read | TERUJI |
| `show gpon profile tcont [nama]` | Katalog profil tcont (MBW) | Read | TERUJI |
| `show gpon profile traffic [nama]` | Katalog profil traffic (SIR/PIR/CBS/PBS) | Read | TERUJI |
| `show gpon global` | (tidak diketahui — butuh argumen) | Read | BELUM DIUJI (ditolak bare) |
| `show gpon olt` | (tidak diketahui — butuh argumen) | Read | BELUM DIUJI (ditolak bare) |
| `show gpon mop` | (tidak diketahui — butuh argumen) | Read | BELUM DIUJI (ditolak bare) |
| `show gpon register-check` | Toggle global register-check (saat ini disable) | Read | TERUJI |
| `show onu-type` | Katalog bawaan 28 tipe ONU didukung firmware | Read | TERUJI |
| `show onu-type-if` | (tidak diketahui — butuh argumen) | Read | BELUM DIUJI (ditolak bare) |
| `terminal length <0-512>` | Kontrol paging per sesi (0=tanpa jeda) | Write (state sesi) | DARI BANTUAN, TIDAK dieksekusi |
| `terminal monitor` | Salin debug ke terminal aktif | Write (state sesi) | DARI BANTUAN, TIDAK dieksekusi |
| `enable` | Masuk mode privileged | Netral | **TIDAK PERLU di device ini** — login langsung privileged |
| `configure terminal` | Masuk mode config | Write (navigasi) | **DILARANG, tidak dieksekusi** |
| `onu <id> type <tipe> sn <SN>` | Registrasi ONU baru | **Write** | DARI REFERENSI PUBLIK, **TERKONFIRMASI SESUAI** via `show running-config interface <gpon-olt>` — TIDAK dieksekusi |
| `interface gpon-onu_R/S/P:<id>` + `name`/`description`/`tcont`/`gemport`/`service-port` | Konfigurasi service per ONU | **Write** | DARI REFERENSI PUBLIK, **TERKONFIRMASI SESUAI STRUKTUR** (nama sub-perintah cocok observasi `show running-config interface <onu>`) — TIDAK dieksekusi |
| `pon-onu-mng <onu>` + `service`/`gemport`/`vlan` | Konfigurasi flow/vlan per ONU | **Write** | DARI REFERENSI PUBLIK, **SEBAGIAN cocok** (`flow`/`vlan-filter` ada, TIDAK ada `tr069-mgmt`) — TIDAK dieksekusi |
| `tr069-mgmt 1 state unlock` / `acs <url>` / `tag pri 0 vlan <V>` | Konfigurasi TR-069 per ONU | **Write** | **TIDAK DITEMUKAN di firmware ini** — lihat koreksi Sesi 7, akses TR-069 nyatanya lewat `security-mgmt` generik |
| `wr` | Simpan config | **Write** | **DILARANG, tidak dieksekusi** |
| `no onu <id>` | Hapus ONU | **Write** | DARI REFERENSI PUBLIK, **DILARANG, tidak dieksekusi** |

### Item 12 — command dari peta referensi yang TIDAK ada/tidak sesuai di firmware ini

- **`tr069-mgmt ...` (semua varian)** — **TIDAK ADA** di config nyata `pon-onu-mng`. Akses TR-069
  diberikan lewat `security-mgmt 999 ... protocol ftp telnet ssh snmp tr069` (allowlist generik, sama
  untuk kedua ONU_UJI, bukan konfigurasi per-ONU dengan URL/kredensial).
- **`show gpon onu next-available`** — ADA tapi fungsinya BEDA dari dugaan (gemport, bukan ID ONU).
- Selebihnya (`onu <id> type ... sn ...`, struktur `interface gpon-onu_x` dengan `name`/`description`/
  `tcont`/`gemport`/`service-port`) **SESUAI** referensi publik, dikonfirmasi lewat observasi config
  nyata (bukan dieksekusi).
