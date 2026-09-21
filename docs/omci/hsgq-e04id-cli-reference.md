# HSGQ-E04ID (EPON) — CLI Reference

**Status: v0.23.1 (final, verifikasi manual Agung 2026-09-21).** Disusun dari riset manual vendor + sesi
CLI read-only langsung (v0.23.1 OMCI CLI research, 2026-09-21). **Tidak ada data pelanggan/MAC/SNMP
community di dokumen ini** — semua contoh di bawah SINTETIS, bukan data asli yang sempat terlihat
selama sesi.

## Sumber

- **Manual vendor**: *HSGQ EPON OLT Command User Manual V2.2.0* (riset manual Agung, PDF eksternal, tidak
  ada di repo).
- **Sesi CLI langsung**: 4 sesi read-only ke `10.168.100.5` (registry BOSS App: `olt_devices` id=2,
  nama `HSGQ-E04ID-CILED`) via SSH, 2026-09-21. Kredensial dari `olt_devices` (tidak pernah dicatat di
  dokumen ini). Sesi 4 memakai metode bantuan yang dikoreksi: `<cmd> ?` **DENGAN spasi** (bukan
  `<cmd>?`) — ini membuka sub-command sebenarnya (lihat catatan metodologi diperbarui di bawah).
- **Bagian yang belum konsisten dengan manual V2.2.0**: manual menduga login mendarat langsung di mode
  `(config)#`. **Fakta dari sesi nyata: login mendarat di mode VIEW (`OLT-Cileg>`), sama seperti pola
  HSGQ-G02ID** — perlu `enable` (tanpa password tambahan) untuk masuk mode privileged (`OLT-Cileg#`).
  Manual mungkin mendeskripsikan firmware/varian berbeda dari yang genuinely terpasang di device ini.

## Identitas device (dari `show version`, non-sensitif)

| Field | Nilai |
|---|---|
| Software Version | `HSGQ-E04ID_IR_V3.3.3ID_Rel` |
| System Version | `HSGQ-E04ID_IR_V2.0.2_Rel` |
| Hardware Version | `HSGQ-E04ID-hw-version-v3.0` |
| Build time | 2023/10/26 17:05:25 |
| Product | HSGQ-E04ID |
| License | Forever |
| Hostname CLI | `OLT-Cileg` (**beda ejaan** dari nama registry BOSS App `HSGQ-E04ID-CILED` — lihat catatan decision-gate di bawah) |
| Jumlah PON port | **4** (`interface epon 1`–`4`), konsisten dengan nama produk "E04ID" |
| Jumlah uplink | 8× `interface ge 1`–`8` |

## Alur login & mode

```
$ ssh <user>@10.168.100.5
Password: ****
OLT-Cileg> enable
OLT-Cileg#
```

- Mode awal setelah login: **VIEW** (`>`), bukan config.
- `enable` di mode view **tidak meminta password tambahan** (dikonfirmasi 2 sesi berbeda).
- **Logout dari mode VIEW**: satu `exit` langsung menutup sesi total ("Connection closed by foreign host").
- **Logout dari mode `#`** (belum genuinely diuji sampai tuntas — sesi kedua terputus di tengah karena
  timeout output panjang): pola dugaan `exit` (turun ke view) lalu `exit` lagi (logout) — **[BELUM
  DIVERIFIKASI TUNTAS]**, meski desain skrip riset sudah mengasumsikan ini dan tidak pernah menabrak error.

## Command mode `#` (dari `?`, TERUJI — daftar lengkap, tidak ada paging terpicu)

| Command | Fungsi (dari CLI) | Read/Write | Status |
|---|---|---|---|
| `configure` | Configuration from vty interface | **Write** (masuk mode config) | TERUJI (nama ada di daftar `?`; TIDAK PERNAH dieksekusi) |
| `copy` | Copy configuration | Write | DARI MANUAL/BELUM DIUJI |
| `disable` | Turn off privileged mode command | Netral (turun mode) | BELUM DIUJI |
| `end` | End current mode and change to view mode | Netral (turun mode) | BELUM DIUJI |
| `exec-timeout` | Set the EXEC timeout | Write (kalau diberi argumen) | `show exec-timeout` TERUJI (baca) — versi **set** BELUM DIUJI, di denylist |
| `exit` | Exit current mode and down to previous mode | Read-only (navigasi) | **TERUJI** — dipakai untuk logout di setiap sesi |
| `help` | Description of the interactive help system | Read-only | BELUM DIUJI |
| `logout` | User logout | Read-only (navigasi) | Dikirim di setiap sesi sebagai bagian urutan logout; efeknya belum genuinely terpisah dari `exit` (`exit` dari mode view sudah menutup koneksi duluan) |
| `no` | Configuration's defaults will be set | Write | DARI MANUAL/BELUM DIUJI, **denylist** |
| `quit` | Exit current mode and down to previous mode | Read-only (navigasi) | BELUM DIUJI |
| `show` | Show running system information | **Read-only** | TERUJI — lihat sub-command di bawah |
| `terminal` | Set terminal line parameters | Kemungkinan bisa read (`?`) atau write (set) | **Kandidat kontrol paging** — lihat bagian tersendiri di bawah, BELUM DIUJI |
| `ttest` | Modify enable password parameters | Write | DARI MANUAL/BELUM DIUJI, **denylist** |
| `who` | Display who is on vty | Read-only | BELUM DIUJI di E04ID (baru ditambahkan ke allowlist) |

### `show ...` yang sudah TERUJI

| Command | Fungsi | Contoh output (real, non-sensitif) |
|---|---|---|
| `show version` | Firmware/hardware/SN/license | Lihat tabel identitas di atas |
| `show memory` | Statistik memori internal sistem (Vector/Thread/Buffer/Hash/dll — angka murni, bukan data pelanggan) | `Command desc: 15303`, dll — aman dikutip apa adanya kalau dibutuhkan |
| `show exec-timeout` | Timeout idle sesi CLI | `Exec-timeout:180(s)` |
| `show startup-config` | **Dump seluruh konfigurasi** — TERUJI tapi **output SANGAT panjang** (ratusan baris, mem-page dengan `--More--`), sesi kedua kehabisan waktu/ruang sebelum sampai akhir. Isinya dirangkum di bagian "Pola konfigurasi ONU" di bawah, **tidak dikutip mentah**. | (tidak dikutip — lihat kebijakan di atas) |

## Pola konfigurasi ONU (dari `show startup-config`, SINTETIS — bukan data asli)

Dua blok data ONU yang **konsep berbeda**, ditemukan di config real tapi disajikan di sini sebagai
sintaks generik + contoh sintetis:

**1. Binding awal (per-PON, di dalam `interface epon N`)**
```
bind-onu <onu-id> mac <MAC-address> onu-type <tipe> desc "<Nama - Kontak>"
onu confirm onu-id <onu-id>
```
- `desc` menampung **nama + kontak pelanggan** dalam satu string bebas format — pola yang teramati:
  kebanyakan `"Nama - NomorTelepon"`, minimal 1 kasus real berisi **link Google Maps** sebagai pengganti
  nama sama sekali (format tidak konsisten, bukan field terstruktur).
- `onu confirm onu-id <N>` — baris terpisah, tampaknya konfirmasi/aktivasi binding yang baru dibuat
  `bind-onu` di atasnya (persis pola "deny-list confirm" yang diduga sebelumnya, tapi nama command
  aslinya `bind-onu`/`onu confirm`, bukan istilah "deny-list add/approve" seperti dugaan riset manual
  awal — **kemungkinan istilah "deny-list" di riset manual adalah istilah generik, bukan nama command
  real firmware ini**).
- **Kedua command ini DENYLIST — tidak pernah dieksekusi, sintaks murni dari observasi config tersimpan.**

**2. Penamaan per-ONU (di luar blok epon, satu blok per ONU)**
```
interface onu <pon>/<onu-id>
name <NamaSingkat>
exit
```
- Field `name` **terpisah** dari `desc` di atas — nilainya nama pendek **tanpa spasi** (pola: nama
  digabung tanpa pemisah, kemungkinan auto-strip spasi oleh device atau input manual tanpa spasi).
- Ditemukan **1 kasus** nama yang menyertakan nomor port sebagai sufiks pembeda (pola disambiguasi
  manual untuk nama yang mirip/duplikat).
- **[PERTANYAAN DECISION-GATE]**: ada 2 kolom nama per ONU (`desc` di blok `bind-onu`, `name` di blok
  `interface onu`) — belum jelas mana yang jadi *source of truth* untuk skema penamaan
  "Nama - CID" yang direncanakan v0.23.x, atau apakah keduanya perlu diisi konsisten.

**3. Field tambahan yang belum diantisipasi manual**
```
interface onu <pon>/<onu-id>
onu-ipmgmt <IP> <netmask> <gateway> cvlan <VLAN> pri 0
name <NamaSingkat>
exit
```
- Ditemukan **1 kasus** — konfigurasi IP management per-ONU individual, di luar cakupan pembahasan
  sebelumnya. Relevansinya untuk skema OMCI (mis. remote management per-ONU) belum dibahas — dicatat
  sebagai temuan, bukan keputusan.

**4. Karakteristik lain yang teramati**
- **onu-id TIDAK sequential** — ada banyak celah nomor di tiap PON yang teramati (PON 1 dan PON 3
  sejauh ini; PON 2 dan PON 4 belum sempat terlihat sebelum sesi terpotong). Konsisten dengan ONU yang
  pernah dihapus/dipindah tanpa nomor lamanya dipakai ulang.
- **`onu-authorize`** (disebut di riset manual sebagai command untuk melihat mode/tipe autentikasi PON
  port) — **belum pernah dijalankan atau terlihat di config** — **[TIDAK BISA DIVERIFIKASI]** sampai ada
  sesi yang eksplisit mengujinya (rencana Sesi 3, node `interface epon`).
- **Karakter khusus dalam nilai**: tanda kutip ganda membungkus `desc`, spasi hanya muncul di `desc`
  (tidak di `name`), tanda hubung sebagai pemisah nama-kontak di `desc`, minimal 1 kasus URL utuh
  sebagai isi `desc` (bukan nama sama sekali).
- **Batas panjang `desc`/`name`, karakter yang diizinkan** — **[TIDAK BISA DIVERIFIKASI]** dari observasi
  pasif config tersimpan saja; perlu dicek lewat `?` di node `interface onu` (rencana Sesi 3).

## Pager (`--More--`)

- **Terkonfirmasi ada** untuk output panjang (`show startup-config`, dan juga `?` di node `interface
  epon N` — daftar command node cukup panjang untuk memicu satu kali `--More--`), device berhenti tiap
  ~20-an baris, lanjut dengan menekan **spasi**. Skrip riset menangani ini otomatis (deteksi pola,
  bukan jumlah spasi tetap).
- **Sintaks lengkap `terminal length` (Sesi 4, TERUJI via bantuan)**: `terminal length <0-512>` —
  *"Number of lines on screen (0 for no pausing)"*. Ini **satu-satunya baris teks bantuan** yang
  ditampilkan device — **tidak ada kata "session"/"vty"/"temporary"/"permanent"/"save"/"startup-config"
  apa pun** yang menjelaskan apakah nilai ini berlaku permanen (tersimpan ke config) atau cuma untuk
  sesi/vty yang sedang aktif.
- **`terminal length 0` — TIDAK DIEKSEKUSI di Sesi 4**, sesuai syarat eksplisit dari Agung ("kalau
  bantuan tidak jelas soal itu, jangan jalankan"). Karena bantuan di atas genuinely tidak menyebut
  cakupan sama sekali (bukan mengonfirmasi permanen, bukan juga mengonfirmasi per-sesi), keputusan
  default aman (SKIP) diambil. **Command dan nilai `0` (kemungkinan "unlimited", konvensi umum banyak
  CLI Cisco-like) masih [TIDAK BISA DIVERIFIKASI]** — butuh keputusan eksplisit dari Agung apakah tetap
  mau mengeksekusinya meski cakupannya tidak jelas dari bantuan, atau coba jalur lain (mis. cek manual
  vendor V2.2.0 soal `terminal length`).

## Command mode `configure` → `interface epon N` (TERUJI — daftar lengkap dari `?`)

Prompt di node ini: `OLT-Cileg(config-epon-N)#`. Daftar penuh (37 command, satu kali `--More--`
terlewati otomatis):

| Command | Fungsi (dari CLI) | Catatan |
|---|---|---|
| `bind-onu` | Bind whitelist onu configuration | **Denylist** — argumen level-1 TERUJI Sesi 4 (`bind-onu ?` → `<1-64>` ONU ID / `mac` Mac address configuration / `upgrade-type` Onu upgrade type). Kedalaman lebih jauh (setelah `mac`, `onu-type`, `desc`, batas panjang) **belum diuji** — hanya level pertama, lihat catatan metodologi |
| `blacklist` | Pon onu configuration | Argumen level-1 TERUJI Sesi 4 (`blacklist ?` → `add` Add operation / `delete` Delete operation). Sub-sintaks lebih dalam belum diuji |
| `ctc-ver` | ONU CTC Version set | Belum diuji |
| `end` | End current mode and change to view mode | **TERUJI** — dipakai untuk keluar node |
| `exit` | Exit current mode and down to previous mode | Belum dipakai di node ini (pakai `end`) |
| `help` | Description of the interactive help system | Belum diuji |
| `interface` | Interface configuration | Belum diuji |
| `ip` | IP Address | Kemungkinan terkait `onu-ipmgmt` yang terlihat di startup-config |
| `loid` | LOID configuration | Belum diuji, ada di denylist ("loid add") |
| `loopback-onu` | Loopback onu configuration | Belum diuji |
| `mac` | Mac address configuration | Belum diuji |
| `mirror` | Add mirror | Belum diuji |
| `no` | Configuration's defaults will be set | Denylist |
| `onu` | Pon onu configuration | Sub-command belum terlihat (lihat catatan metodologi di bawah) |
| `onu-authorize` | Pon onu configuration | **TERUJI tanpa argumen** (lihat hasil di bawah) **+ argumen level-1 TERUJI Sesi 4** (`onu-authorize ?` → `mode` ONU authorize mode / `type` ONU authorize type / `<cr>` bare-Enter valid, mengonfirmasi bahwa menjalankan tanpa argumen = menampilkan mode/tipe saat ini) |
| `onu-catv` | ONU CATV Configuration | Belum diuji |
| `onu-crypto` | ONU Crypto configuration | Belum diuji |
| `onu-deregister` | Pon onu configuration | Argumen level-1 TERUJI Sesi 4 (`onu-deregister ?` → `<1-64>` ONU deregister operation / `<cr>` bare-Enter valid) — **write, tetap denylist, tidak pernah dieksekusi dengan argumen** |
| `onu-laser-ctrl` | EPON ONU laser configuration | Belum diuji |
| `onu-reboot` | Pon onu reboot | Belum diuji, **write** |
| `onu-upgrade` | Onu upgrade function | Belum diuji, **write**, denylist ("update") |
| `onu-wanc` | ONU Wan configuration | Belum diuji |
| `onu-wlan` / `onu-wlan-batch` | ONU WLAN Configuration | Kandidat untuk set SSID/password per-ONU — belum diuji |
| `onusort` | **Sort onu display** | Argumen TERUJI Sesi 4 (`onusort ?` → `WORD` *"Sort string, just specified onu list, eg: 28,7,5,6"*). **Koreksi dari Sesi 3**: ini BUKAN command untuk menemukan/menampilkan daftar ONU — butuh sudah tahu ID ONU-nya (input CSV, mis. `28,7,5,6`), fungsinya murni MENGATUR URUTAN TAMPILAN untuk ID yang sudah diketahui. Ditarik dari status "kandidat terbaik" |
| `p2p` | p2p configuration | Belum diuji |
| `performance` | Performance configuration | Konsisten dengan `performance monitor enable ...` di startup-config |
| `pon` | EPON Port configuration | Belum diuji |
| `port` | Port configurations | Belum diuji |
| `profile` | PON Profile configurations | Belum diuji |
| `quit` | Exit current mode and down to previous mode | Belum dipakai |
| `reject-onu` | Reject onu configuration | Argumen level-1 TERUJI Sesi 4 (`reject-onu ?` → `<1-64>` ONU ID — langsung minta ID, tidak ada sub-kata lain). **write, tetap denylist** |
| `rogueonu` | Rogue onu configuration | Belum diuji — **muncul di daftar `show ?` Sesi 4 (`rogueonu — Rogue onu configuration`) tapi TERLEWAT dari auto-discovery karena baris ini bercampur dengan marker pager `--More--` saat capture** (keterbatasan teknis skrip, bukan device) — kandidat ONU-related yang belum sempat di-`show rogueonu ?` |
| `show` | Show running system information | **Sub-command penuh TERUJI Sesi 4** — lihat daftar 31 sub-command + kedalaman `show onu-*` di bagian baru di bawah |
| `ctc-info` | ONU ID. `<1-64>` (dari `show ?`, bukan `show ctc-info`) | Muncul sebagai sub-command `show` — namanya tidak eksplisit "onu" jadi juga terlewat dari auto-discovery Sesi 4, **belum di-`show ctc-info ?`** |
| `sipuser` | Voipuser configuration | Belum diuji |
| `sla-down` / `sla-up` | SLA configuration | Denylist ("sla-up") |
| `splitter` | Splitter configuration | Belum diuji |
| `vlan` | Vlan configuration | Konsisten dengan `vlan hybrid ...` di startup-config |

**Tidak ada command bernama eksplisit "onu-info-list"/"onu pending"/"auto-find"** sebagai TOP-LEVEL
command node ini. Tapi Sesi 4 menemukan **`show onu-info`** (sub-command dari `show`, bukan top-level) —
lihat bagian "`show` — sub-command lengkap (Sesi 4)" di bawah untuk detail; ini kandidat kuat baru
menggantikan `onusort` (yang sudah terkoreksi bukan tool pencarian ONU — lihat baris `onusort` di atas).

### Catatan metodologi penting: `<cmd>?` (TANPA spasi) vs `<cmd> ?` (DENGAN spasi)

`show?`/`onu?` (**tanpa spasi** sebelum `?`, dipakai keliru di Sesi 3) hanya menampilkan command itu
sendiri sebagai satu entri — device memperlakukan ini sebagai *ambiguous-match-on-prefix* untuk kata
tersebut, bukan "tampilkan sub-command". **Dikoreksi di Sesi 4: `show ?` DENGAN SPASI genuinely
menampilkan daftar sub-command yang benar** (31 sub-command, lihat bagian baru di bawah) — metode ini
dipakai konsisten sejak Sesi 4 untuk semua query bantuan level-1 dan level-2 (`bind-onu ?`,
`onu-authorize ?`, dst, dan `show onu-info ?`, `show onu-wanc ?`, dst).

### `onu-authorize` (tanpa argumen) — hasil TERUJI

```
----------------------------------------------------------------------------------------------------
 PON-PORT AUTH-MODE AUTH-TYPE
----------------------------------------------------------------------------------------------------
    pon2       mac    manual
----------------------------------------------------------------------------------------------------
```

**PON 2: AUTH-MODE = `mac`, AUTH-TYPE = `manual`.** Ini mengonfirmasi pola yang sudah terlihat di
`show startup-config`: ONU baru **tidak otomatis ter-authorize** — device dikonfigurasi mode manual,
autentikasi berbasis MAC address, perlu eksplisit `bind-onu` sebelum ONU bisa online. Belum diketahui
apakah PON lain (1, 3, 4) punya konfigurasi auth-mode yang sama atau berbeda — **[TIDAK BISA
DIVERIFIKASI]** untuk PON selain 2 di sesi ini.

### `show` — sub-command lengkap (Sesi 4, TERUJI via `show ?` DENGAN spasi)

31 sub-command ditemukan di node `interface epon N` (satu kali `--More--` terlewati otomatis):

`blacklist`, `ctc-info`, `interface`, `loidlist`, `loopback-onu`, `module`, `onu-catv`, **`onu-info`**,
`onu-info-alarm`, `onu-upgrade-status`, `onu-version`, `onu-wanc`, `onu-wlan`, `optical-info`,
`optical-rssi`, `optical-state`, `p2p-state`, `perf-history`, `perf-monitor`, `perf-setting`,
`performance`, `profile`, `rogueonu`, `sla`, `sla-down`, `splitter`, `state`, `statistic`,
`storm-control`, `vlan`, `voipinfo`.

**Kedalaman level-2 yang sudah TERUJI (Sesi 4, `show <sub> ?`)**:

| Sub-command | Argumen (dari bantuan) |
|---|---|
| `show loopback-onu ?` | `reject-state` — Reject state |
| `show onu-catv ?` | `<1-64>` — ONU ID |
| **`show onu-info ?`** | **`all` — All ONU Configuration / `mac` — ONU MAC Configuration / `onu-id` — ONU ID `<1-64>`** |
| `show onu-info-alarm ?` | `<1-64>` — Onu id |
| `show onu-upgrade-status ?` | `<1-64>` — *(label bantuan device sendiri tertukar/typo: tertulis "All ONU upgrade status")* / `all` — *(label device: "ONU ID. <1-64>", juga tampak tertukar)* — **kemungkinan besar bug label di firmware sendiri, bukan salah baca kita; makna sebenarnya `<1-64>`=per-ID, `all`=semua, belum dikonfirmasi lewat eksekusi nyata** |
| `show onu-version ?` | `all` — All onu configuration |
| `show onu-wanc ?` | `<1-64>` — ONU ID |
| `show onu-wlan ?` | `<1-64>` — ONU ID |

**`show onu-info all` — KANDIDAT TERKUAT untuk "melihat daftar/status semua ONU" (termasuk kemungkinan
ONU pending/belum ter-bind)** — belum pernah dieksekusi (hanya level bantuan, sesuai batasan sesi ini),
tapi ini satu-satunya sub-command `show` yang secara eksplisit menawarkan opsi `all` untuk **konfigurasi
ONU** (bukan cuma alarm/versi/upgrade-status satu ONU spesifik). Rekomendasi untuk sesi berikutnya (kalau
disetujui): coba `show onu-info all ?` dulu (masih level bantuan) sebelum eksekusi nyata.

**Terlewat dari auto-discovery** (nama tidak mengandung kata kunci "onu"/"pending"/"status"/dll secara
otomatis terdeteksi skrip, atau tercampur marker pager): `rogueonu`, `ctc-info` — keduanya kemungkinan
relevan ONU, belum di-`show <x> ?`.

### Temuan tak terduga: log real-time device tercampur di output sesi

Selama `onu ?` dijalankan, muncul 2 baris log sistem device yang **bukan respons terhadap command
kita** — device menampilkan log operasional real-time ke sesi VTY aktif:
```
[timestamp]  Info: ONU <pon>/<id> <MAC address, di-mask> ONU authorization success
[timestamp]  Info: ONU <pon>/<id> <MAC address, di-mask> ONU link up
```
Ini terjadi di **PON 1** (bukan PON 2 yang sedang kita akses) — event genuinely dari ONU pelanggan lain
yang authorize/online saat itu juga, tidak terkait command kita sama sekali. **Temuan berguna**: log
`ONU authorization success` → `ONU link up` ini kemungkinan bisa jadi sumber jawaban untuk "berapa lama
ONU baru sampai online setelah dicolok" (selisih waktu antar 2 baris log ini, atau baris log sebelumnya
soal deteksi fisik ONU) — **[TIDAK BISA DIVERIFIKASI lebih jauh]** tanpa sesi khusus yang mengamati log
ini secara sengaja (di luar scope Sesi 3).

**Terulang di Sesi 4, mengonfirmasi ini bukan kejadian sekali saja**: satu baris log serupa muncul lagi
tanpa diminta saat query `bind-onu ?` dijalankan — format `[timestamp] Info: ONU <pon>/<id> <MAC,
di-mask> Onu deregister, Reason:Laser out` (event ONU LAIN yang deregister/putus koneksi fisik, bukan
otorisasi kali ini — MAC address tidak dikutip, sesuai kebijakan data). **Jawaban untuk pertanyaan
"apakah log event real-time bisa diaktifkan/dibaca lewat sesi"**: log ini tampak **AKTIF SECARA DEFAULT**
di sesi VTY manapun yang sedang login — tidak ada command apa pun yang dijalankan untuk "mengaktifkan"-nya
(tidak ada `logging`/`monitor`/`debug` yang dikirim di Sesi 3 maupun Sesi 4), log operasional device
langsung tercampur ke output sesi begitu kejadian terjadi di device, terlepas dari command apa yang
sedang dijalankan. **Belum diuji**: apakah ini bisa DIMATIKAN (mis. `no logging monitor` atau sejenis) —
di luar scope, tidak dicoba karena berisiko mengubah state sesi tanpa persetujuan eksplisit.

## Command yang DILARANG (denylist, dari manual + observasi CLI nyata, sintaks BELUM DIUJI kecuali disebut lain)

`configure` (masuk mode — netral, sudah dipakai untuk navigasi, tapi command DI DALAMNYA yang mengubah
state tetap denylist), `copy`, `no`, `ttest`, `show history`, `bind-onu`, `onu confirm`,
`blacklist add/remove`, `loid add`, `onu-authorize mode ...` (set — beda dari `onu-authorize` tanpa
argumen yang sudah TERUJI read-only), `sla-up`, `port-vlan ...`, `save`, `erase`, `update`,
`user add/delete/password`, `ssh-server`, `hostname` (set), `exec-timeout` (set),
`terminal length <N>` (set — SUDAH ditemukan sintaksnya via help, TAPI BELUM DIEKSEKUSI, mengubah state
sesi), `onu-reboot`, `onu-upgrade`, `onu-deregister`, `reject-onu`, `onu-wlan`/`onu-wlan-batch` (set),
`ip` (set), `mirror` (set).

## [TIDAK BISA DIVERIFIKASI] — ringkasan (diperbarui Sesi 4)

- **Command untuk melihat status/daftar ONU pending (belum di-`bind`)** — kandidat terkuat sekarang
  **`show onu-info all`** (level bantuan TERUJI, eksekusi nyata belum dicoba). `onusort` sudah dikoreksi
  BUKAN kandidat (murni pengurutan tampilan berbasis ID yang sudah diketahui).
- **Sintaks lengkap `bind-onu` di luar level-1** — level-1 TERUJI (`<1-64>`/`mac`/`upgrade-type`), tapi
  urutan penuh setelah `mac` (format MAC, lalu `onu-type`, `desc`, batas panjang `desc`/`name`) belum
  diuji via bantuan berjenjang (`bind-onu mac ?`, dst) — hanya diketahui dari observasi `show
  startup-config` (Sesi 2), bukan dari bantuan resmi.
- **`terminal length 0`** — sintaks+deskripsi TERUJI (`<0-512>`, "0 for no pausing"), TAPI **tidak
  dieksekusi** karena bantuan tidak menyebut cakupan sesi vs permanen (sesuai syarat konservatif dari
  Agung). Efek nyata `0` dan cakupannya masih [TIDAK BISA DIVERIFIKASI].
- **`rogueonu`/`ctc-info`** — dua sub-command `show` yang relevan ONU tapi terlewat dari auto-discovery
  Sesi 4 (lihat catatan di tabel node), belum di-`show <x> ?`.
- Batas panjang & karakter yang diizinkan untuk `desc`/`name` — masih belum diverifikasi lewat bantuan
  resmi.
- Logout persis dari mode `#` (`exit` × berapa kali) — **TERUJI dan bekerja bersih** sejak Sesi 3,
  dikonfirmasi ulang di Sesi 4.
- PON 1, 3, 4 — auth-mode/auth-type belum dicek (hanya PON 2 yang diuji: `mac`/`manual`).
- Apakah log event real-time (deregister/authorization/link-up) bisa **dimatikan** — belum dicoba
  (di luar scope, berisiko mengubah state sesi).

## Catatan decision-gate

- **Hostname device (`OLT-Cileg`) vs nama registry BOSS App (`HSGQ-E04ID-CILED`)** — beda ejaan/singkatan,
  perlu diputuskan mana yang jadi acuan penamaan skema OMCI baru, atau keduanya independen.
- **Dua kolom nama per ONU** (`desc` di `bind-onu`, `name` di `interface onu`) — perlu keputusan mana yang
  dipakai/diisi untuk format "Nama - CID" yang direncanakan.
