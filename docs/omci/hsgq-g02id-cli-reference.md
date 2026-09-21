# HSGQ-G02ID (GPON) — CLI Reference

**Status: v0.23.1 (final, verifikasi manual Agung 2026-09-21).** Disusun dari 1 sesi CLI read-only
(Tugas 2, 2026-09-21) ke `10.168.100.10` (registry BOSS App: `olt_devices` id=4, nama
`HSGQ-G02ID-BUMIREJA`). **Tidak ada data pelanggan/MAC/password/SNMP community di dokumen ini** — semua
contoh di bawah SINTETIS atau murni struktural (nama field/perintah), bukan nilai asli yang sempat
terlihat selama sesi. Output mentah `show startup-config` **sempat ditulis sementara di dalam container
Docker sekali-pakai** (dibaca lewat sesi `expect` yang berjalan di sana), **dihapus dari container itu
segera setelah ekstraksi struktural selesai** — tidak pernah disalin/ditulis ke repo atau host, dan
container itu sendiri (bersama isinya) dibuang begitu sesi berakhir (`docker run --rm`).

## Sumber

- **Sesi CLI langsung**: 1 sesi berhasil (2026-09-21, Tugas 2) — 2 percobaan sebelumnya (Tugas 2 awal)
  gagal (`EOF sebelum prompt`, lalu `Permission denied` eksplisit) memakai kredensial teknisi lama —
  lihat catatan kredensial di bawah.
- **Kredensial — PERUBAHAN PENTING, SEMENTARA**: Agung mengganti kredensial `olt_devices` id=4 memakai
  **akun `root`**, khusus untuk fase riset CLI v0.23.1 ini. Ini BUKAN kredensial permanen — lihat
  "Catatan decision-gate" di bawah untuk kewajiban migrasi ke akun non-root sebelum otomasi v0.23.2.
- **`Maximum Logins` akun root = 1`** — dicek via `who` di awal sesi: 2 vty aktif saat sesi kami mulai
  (`vty[10]` user kosong dari `127.0.0.1`, `vty[11]` user `root` dari `::1` — ini sesi kami sendiri).
  Login berhasil karena Agung sudah menutup sesi root miliknya sebelum kami connect. **Password TIDAK
  PERNAH dicetak/dikutip/disimpan di mana pun** selama investigasi ini.

## Identitas device (dari `show version`, non-sensitif — Base MAC Address disengaja tidak dikutip)

| Field | Nilai |
|---|---|
| Software Version | `HSGQ-G02ID_IGC_V1.2.14ID_Rel` |
| System Version | `HSGQ-G02ID_0` |
| Hardware Version | `HSGQ-G02ID-hw-version-v2.0` |
| SerialNumber (device, bukan ONU) | `<device-serial, DI-MASK>` |
| Product | HSGQ-G02ID |
| License | Forever |
| Hostname CLI | `OLT-BUMIREJA` (**beda ejaan** dari nama registry BOSS App `HSGQ-G02ID-BUMIREJA` — pola sama seperti E04ID `OLT-Cileg` vs `HSGQ-E04ID-CILED`, lihat decision-gate) |
| Jumlah PON port terlihat di config | **2** (`interface gpon 1`, `interface gpon 2`) — konsisten nama produk "G02ID" |
| Jumlah uplink terlihat di config | `ge 1`–`4`, `xge 1` (5 port fisik uplink berbeda tipe) |

## Alur login & mode

```
$ ssh root@10.168.100.10
Password: ****
OLT-BUMIREJA> enable
OLT-BUMIREJA#
```

- Mode awal: **VIEW** (`>`), sama seperti E04ID — bukan langsung `#` meski login sebagai `root`.
- `enable` tidak meminta password tambahan.
- Logout: `exit` dari `#` → turun ke `>`, `exit` lagi → menutup koneksi ("Connection closed by foreign
  host"). **TERUJI bersih di sesi ini.**

## Command mode `#` (dari `?` bare, TERUJI)

16 command — **2 command LEBIH BANYAK dari E04ID** (yang cuma 14): G02ID punya `ping` dan `snmp`
tambahan.

| Command | Fungsi (dari CLI) | Catatan |
|---|---|---|
| `configure` | Configuration from vty interface | Tidak dieksekusi sesi ini |
| `copy` | Copy configuration | Belum diuji |
| `disable` | Turn off privileged mode command | Belum diuji |
| `end` | End current mode and change to view mode | Belum diuji |
| `exec-timeout` | Set the EXEC timeout | Belum diuji |
| `exit` | Exit current mode and down to previous mode | **TERUJI** — dipakai logout |
| `help` | Description of the interactive help system | Belum diuji |
| `logout` | User logout | Belum diuji |
| `no` | Configuration's defaults will be set. | Denylist |
| `ping` | Ping command. | **BARU dibanding E04ID** — belum diuji |
| `quit` | Exit current mode and down to previous mode | Belum diuji |
| `show` | Show running system information | **TERUJI** — lihat sub-command di bawah |
| `snmp` | Snmp Info | **BARU dibanding E04ID** — belum diuji, kemungkinan `show`-style read (nama "Snmp Info" menyarankan baca) |
| `terminal` | Set terminal line parameters | **TERUJI level-1** — lihat di bawah |
| `ttest` | Modify enable password parameters | Denylist |
| `who` | Display who is on vty | **TERUJI** — dipakai cek Max Logins |

### `show ?` (level 1, TERUJI)

| Sub-command | Fungsi |
|---|---|
| `exec-timeout` | Show the EXEC timeout |
| `history` | Display the session command history |
| `memory` | Memory statistics |
| `startup-config` | Contentes of startup configuration *(typo firmware sendiri: "Contentes")* |
| `version` | System Version infomation. |

**Catatan**: daftar ini LEBIH PENDEK dari yang ditemukan di node `interface epon N` milik E04ID (yang
punya 31 sub-command `show` termasuk `onu-info`, dll) — **level ini adalah `show` di mode `#` ROOT**,
bukan di dalam sebuah node interface PON. G02ID sesi ini **belum pernah masuk ke `configure`/`interface
gpon N`** — jadi belum diketahui apakah `show` di dalam node itu punya sub-command sebanyak/semirip
E04ID. Ini salah satu tujuan utama proposal Sesi 2 di bawah.

### `show interface ?`, `show optical-state ?`, `show mac ?` (TERUJI)

| Command | Sub-command |
|---|---|
| `show interface ?` | `loopback` — Loopback Interface. *(cuma 1 opsi — mengonfirmasi `interface` di sini scope-nya sempit)* |
| `show optical-state ?` | `all` (All uplink ports) / `ge` (Ethernet port) / `gigaethernet` (Gigabit ethernet port) / `tgigaethernet` (10 Gigabit ethernet port) / `xge` (XEthernet port) |
| `show mac ?` | `address-table` (Mac address table) / `auth` (auth) / `limit` (mac address limit) |

### `terminal ?` (TERUJI) — **temuan penting untuk pertanyaan log real-time**

| Sub-command | Fungsi |
|---|---|
| `length` | Set number of lines on a screen *(sama seperti E04ID, kandidat kontrol paging — belum dieksekusi)* |
| **`monitor`** | **Copy debug output to the current terminal line** |
| `no` | Negate a command or set its defaults |

**`terminal monitor` kemungkinan besar adalah kontrol untuk perilaku "log real-time tercampur ke sesi
VTY" yang teramati pasif di E04ID (Sesi 3 & 4).** Belum dicoba di sesi ini (di luar allowlist yang
disetujui — perlu proposal terpisah kalau mau dicek: `terminal monitor ?` dulu, baru putuskan eksekusi).
`show terminal` (status monitor saat ini, ON/OFF) juga belum dicoba.

## Pola konfigurasi ONU & Auto-Profile TR069-Bind (dari `show startup-config`, TERSTRUKTUR — bukan data asli)

**Ini pengecualian satu kali yang sudah disetujui** — output mentah dibaca sekali via sesi `expect` yang
berjalan di dalam container Docker sekali-pakai, sempat ditulis sementara di dalam container tersebut
(bukan di repo/host), difilter di memori langsung setelahnya, lalu dihapus dari container itu segera
setelah ekstraksi struktural selesai.

### 1. Profil TR069 global (satu untuk seluruh device)

```
ont-tr069-profile add profile-id <N> profile-name "<nama>" url <ACS_URL> user <MASKED> password <MASKED> authrealm <detik>
```
- **1 profile ditemukan** (`profile-id 1`), `url` mengarah ke ACS GenieACS BOSS App (`http://
  genieacs.bajastu.id:7547` — sudah dikenal luas di seluruh dokumentasi proyek ini, bukan rahasia).
  **`user`/`password` di-mask total, tidak dikutip.**

### 2. Line Profile GPON (template layanan)

```
ont-lineprofile gpon profile-id <N> profile-name "<nama>"
 tr069-mode enable 0 dhcp enable mgmtvlan <VLAN-manajemen>
 tr069-profile profile-id <N-tr069>
 tcont <N> dba-profile-id <N>
 gem add <N> tcont <N>
 gem mapping <N> <N> vlan <VLAN-layanan>
 ... (berulang per VLAN layanan)
 commit
 exit
```
- **1 line profile ditemukan** (`profile-id 1`, nama `"tr069"`), mereferensikan `tr069-profile
  profile-id 1` (profil di atas) **di level TEMPLATE**, bukan cuma per-ONT.
- **12 VLAN layanan** di-mapping via `gem add`/`gem mapping` di profile ini (device menggunakan VLAN
  manajemen terpisah dari VLAN layanan — pola sama dengan `mgmtvlan 9` yang sudah dikenal di NAS lain).

### 3. Service Profile GPON

```
ont-srvprofile gpon profile-id <N> profile-name "<nama>"
 ont-port eth adaptive
 igmp-version v2
 port vlan eth 1 transparent
 commit
 exit
```
- **1 service profile ditemukan** (`profile-id 0`, nama `"srvprofile_default_0"`).

### 4. Auto-authorize per PON — TEMUAN UTAMA (beda struktural dari E04ID)

```
interface gpon <N>
ont authorize auto ont-lineprofile-id 1 ont-srvprofile-id 0
port ont-autofind enable
splitter add 1 parent 0 name "root" desc "The root splitter of PON0<N>"
trim-set <N> default
...
```
- **KEDUA PON (1 dan 2) dikonfigurasi `ont authorize auto` + `port ont-autofind enable`** — mode
  **OTOMATIS**, kontras dengan hasil E04ID (`onu-authorize` PON 2 = `AUTH-MODE=mac, AUTH-TYPE=manual`).
  Ini bisa jadi jawaban langsung untuk arah zero-touch provisioning v0.23.0: kalau `auto` genuinely
  berarti "ONU baru langsung ter-bind + dapat line/srv-profile default tanpa perlu `bind-onu` manual",
  G02ID SUDAH berperilaku seperti ini secara default.
- **[TIDAK BISA DIVERIFIKASI]**: apakah baris `ont add <id> sn-auth <serial> ...` untuk tiap ONU di
  bawahnya genuinely TERBENTUK OTOMATIS saat ONT fisik baru pertama kali dicolok (efek nyata dari
  `authorize auto`+`autofind enable`), ATAU semua baris ini hasil provisioning manual/import sebelumnya
  (mis. lewat SmartOLT dulu) dan `auto`/`autofind` baru berlaku untuk ONT BARU ke depan. **Ini pertanyaan
  paling penting untuk keputusan arsitektur OMCI v0.23.0** — perlu observasi live (colok 1 ONT fisik baru,
  amati apakah baris config bertambah sendiri) sebagai langkah terpisah, bukan sesi CLI biasa.

### 5. Binding + penamaan per-ONT

```
ont add <id> sn-auth <serial-hex, DI-MASK> omci ont-lineprofile-id 1 ont-srvprofile-id 0
ont setting <id> name "<Nama - Nomor, DI-MASK>" [desc "<Lokasi ODP, DI-MASK>"]
ont tr069-profile <id> profile-id 1
[ont ipconfig <id> ip-index <N> dhcp vlan <VLAN>]
```
- **`sn-auth`** — serial number ONT dalam hex (16 karakter), **bukan MAC address format standar tapi
  tetap identifier perangkat unik — di-mask sama seperti MAC** sesuai kebijakan.
- **Setiap ONT yang di-bind otomatis dapat baris `ont tr069-profile <id> profile-id 1` TERPISAH** — jadi
  binding ke ACS BUKAN hanya warisan dari line-profile (yang juga sudah referensi TR069 profile 1), tapi
  DIULANG eksplisit per-instance ONT. Konsisten di **171 dari 171** baris `ont add` yang teramati.
- **`name`/`desc` TIDAK otomatis terisi** — baris `ont add` sendiri tidak punya field nama sama sekali;
  `ont setting <id> name "..." desc "..."` adalah baris TERPISAH yang harus ada sendiri. **10 dari 171**
  ONT yang sudah di-`ont add` **TIDAK PUNYA baris `ont setting` sama sekali** (belum diberi nama) — bukti
  langsung bahwa auto-authorize TIDAK mengisi nama otomatis, harus manual.
- **Pola `desc` tidak konsisten**, sama seperti E04ID: dari 161 ONT yang punya `ont setting`, hanya
  **79 (≈49%)** yang juga punya `desc` (lokasi ODP) — sisanya cuma `name`.
- **`ont ipconfig ... dhcp vlan <VLAN>`** — muncul di **7 dari 171** ONT saja (minoritas), kemungkinan
  override eksplisit dari default `mgmtvlan` yang sudah ditetapkan di line-profile — **[TIDAK BISA
  DIVERIFIKASI]** kenapa hanya sebagian yang butuh baris ini.
- **Batas panjang `name`/`desc`** — belum dikonfirmasi lewat bantuan CLI resmi (tidak sempat dicek node
  `interface gpon N` bantuan `?`-nya di sesi ini). **Observasi pasif saja** (bukan batas pasti): nilai
  `name` terpanjang yang teramati saat ini ~38 karakter, `desc` terpanjang ~40 karakter.
- **onu-id per PON**:
  - **PON 1**: 119 ONT terikat, rentang ID 0–122, **4 celah** (ID 1, 20, 28, 94 tidak dipakai) — pola
    sama seperti E04ID (ID tidak sequential, konsisten dengan ONT yang pernah dihapus/dipindah).
  - **PON 2**: 52 ONT terikat, rentang ID 0–51, **tidak ada celah** (sequential penuh) — kontras dengan
    PON 1, mengindikasikan PON 2 kemungkinan lebih baru/belum pernah ada penghapusan ONT.

### 6. VLAN & interface fisik

- `vlan standard 1,9,10,69,101,105,110,111,120,130,131,140,151,172,251` — **15 VLAN** dikenal device ini
  (jauh lebih banyak dari `test-x86-bajastu`/`ro-hotspot` yang cuma beberapa VLAN — device ini melayani
  site yang jauh lebih kompleks/banyak layanan).
- Kedua `interface gpon N` + `ge 1/2/4` + `xge 1` di-set `vlan hybrid ... tagged` dengan daftar VLAN yang
  hampir sama (14 dari 15 VLAN, minus VLAN 1) — `ge 3` di-set `vlan mode trunk` (beda pola, kemungkinan
  uplink utama).
- `interface vlanif 1` — IP manajemen device (`10.168.100.10`, sudah dikenal di registry BOSS App) +
  1 IP sub tambahan pada subnet lokal berbeda — **detail IP tidak dikutip lebih jauh** di sini (bukan
  kategori wajib-mask, tapi tidak relevan untuk ringkasan struktural ini).
- `ont-num-per-page 256` — pengaturan pagination TAMPILAN untuk perintah `ont` (256 baris per halaman) —
  relevan untuk command `show`/`ont` yang menampilkan daftar ONT nantinya.

### 7. Setting global lain (non-sensitif, struktural saja)

`route default gw <IP>`, `dns primary/secondary <IP>`, `ntp enable ntp-server-ip pool.ntp.org`,
`timezone "Asia/Jakarta"`, `ssh-server start`, `system-monitor enable interval 300 max-count 512`,
`system-monitor alarm memory enable 80`. **`snmp community-cfg read/write <MASKED>`, `snmp contact`,
`snmp location`** — ADA di config (community read DAN write ditemukan, keduanya string yang SAMA — **di-mask
total, tidak dikutip**, meski nilainya kemungkinan sudah pernah tercatat di tempat lain di codebase ini
untuk device serupa).

## [TIDAK BISA DIVERIFIKASI] — ringkasan

- **Apakah `ont add` per-ONT genuinely otomatis terbentuk dari `authorize auto`+`autofind enable`**, atau
  hasil provisioning manual/import lama — pertanyaan paling penting untuk arah OMCI, butuh observasi live.
- Sub-command `show`/command lain di dalam node `configure` → `interface gpon N` (belum pernah dimasuki
  sesi ini) — apakah semirip/sebanyak node `interface epon N` milik E04ID.
- Apakah `terminal monitor` adalah kontrol ON/OFF untuk log real-time yang tercampur di sesi (kandidat
  kuat, belum diuji sama sekali).
- Batas panjang & karakter yang diizinkan untuk `name`/`desc` — cuma observasi pasif, bukan dari bantuan
  resmi.
- Command `ping` dan `snmp` (baru dibanding E04ID) — fungsinya belum diuji sama sekali.
- `show <sub> ?` (kedalaman level-2 untuk `show version`/`memory`/`startup-config`/`exec-timeout`/
  `history`) — belum dicoba.

## Command yang DILARANG (denylist, sama semangat dengan E04ID)

`configure`+isinya yang menulis (`ont-tr069-profile add/set`, `ont-lineprofile ...`, `ont-srvprofile
...`, `splitter add`, `trim-set`, `ont add/setting/tr069-profile/ipconfig`, `vlan ...`, `interface
vlanif ...` set), `copy`, `no`, `ttest`, `ping` (belum diuji — berpotensi write/berdampak jaringan
tergantung target), `snmp` (set — belum diuji apakah ada varian tulis), `terminal length <N>`/`terminal
monitor <on/off>` (set — kandidat kontrol state sesi, sama seperti E04ID), `exec-timeout` (set),
`ssh-server`/`hostname`/`route`/`dns`/`ntp`/`timezone` (set), `save`/`erase`.

## Catatan decision-gate

- **Kredensial `root` untuk G02ID BERSIFAT SEMENTARA** — khusus fase riset CLI v0.23.1 atas keputusan
  Agung. **Backlog wajib untuk v0.23.2 (otomasi)**: akun BOSS App yang benar-benar dipakai untuk
  integrasi harus **non-root**, idealnya akun terbatas khusus (pola sama seperti `boss-api-*` di NAS
  Mikrotik) — jangan pernah menjadikan akun `root` sebagai kredensial permanen di `olt_devices` untuk
  device ini.
- **Hostname device (`OLT-BUMIREJA`) vs nama registry BOSS App (`HSGQ-G02ID-BUMIREJA`)** — beda
  ejaan/singkatan, sama seperti E04ID — perlu diputuskan mana yang jadi acuan penamaan skema OMCI baru.
- **Dua kolom nama per ONT** (`name`+`desc` di `ont setting`) — sama pertanyaan open seperti E04ID
  (di sana `desc` di `bind-onu` vs `name` di `interface onu`) — device G02ID JUSTRU menyatukan keduanya
  dalam SATU baris `ont setting`, jadi ini kandidat lebih sederhana untuk skema "Nama - CID" v0.23.x
  dibanding pola E04ID yang dua baris terpisah.
- **`authorize auto` G02ID vs `mac`/`manual` E04ID** — dua OLT di registry BOSS App yang sama-sama masih
  aktif punya FILOSOFI PROVISIONING BERBEDA secara default. Keputusan desain OMCI v0.23.0 perlu
  mengakomodasi kedua mode ini (atau menstandarkan salah satu di semua OLT — keputusan bisnis, bukan
  teknis semata).

---

## Sesi 2 (G02ID) — SELESAI (2026-09-21)

**Login**: satu percobaan, berhasil (`who` di awal: 2 vty, root = sesi kami). `SESSION_END_CLEAN`.
Node `configure` → `interface gpon 1` dimasuki hanya untuk navigasi (tidak ada eksekusi write apa pun).

### Command mode `interface gpon N` (bare `?`, TERUJI — 30 command + `<cr>`)

`black-ont` (Black ont configuration — **SET**, beda dari `show black-ont` di bawah), `description`,
`discard`, `end`, `exit`, `flow-control`, `help`, `interface`, `mac`, `mirror`, `mtu`, `no`, **`ont`**
(ONT configuration — lihat sub-command di bawah), **`ont-autofind`** (PON ont autofind — sub-command
tunggal `delete`, lihat di bawah), `ont-catv-user`, `ont-dhcp`, `ont-gem-rate`, `ont-replace`,
`ont-restore-hw`, `ont-restore-zte`, `ont-upgrade`, `ont-user-set`, `ont-wlan-client-delete`, `p2p`,
`perf-ont`, `performance`, `pon-roam`, `pon-unknown-transmit`, `port`, `quit`, `rogue-onu-detect`,
`show`, `speed`, `splitter`, `switchport`, `trim-set`, `vlan`.

**`ont-lineprofile`/`ont-srvprofile`/`terminal` TIDAK ADA di node ini** (`% There is no matched
command.` untuk ketiganya) — **mengonfirmasi struktural**: kedua profile ini adalah command
GLOBAL (di `(config)#` langsung, bukan nested di `interface gpon N`), sesuai temuan awal dari
`show startup-config` (Tugas 2) yang menampilkannya sebagai baris top-level. `terminal` (length/monitor)
**HANYA bisa diakses dari mode `#` root** — TIDAK dari dalam node manapun.

### `show ?` di node ini (30 sub-command, TERUJI — dikutip persis dari transkrip live, BUKAN dari hasil ekstraksi otomatis skrip yang keliru, lihat catatan bug di bawah)

`black-ont` (**Ont deny list** — `show black-ont` bare/`<cr>`, TERUJI level-2, kemungkinan besar
**inilah command untuk melihat daftar ONT yang di-blacklist/ditolak**), `gemport`, `gpon`, `interface`,
`ont` (sub-command level-2 TERUJI: `eth-port-state`/`ipconfig`/`port-attr`/`pots-port-state`/
`sipconfig`/`statistic` — semua per-ONT-ID, bukan daftar semua ONT), **`ont-autofind`** (`show
ont-autofind` bare/`<cr>`, TERUJI level-2 — **KANDIDAT TERKUAT untuk "melihat ONT baru yang
menunggu/autofind"**), `ont-capability`, `ont-catv`, **`ont-info`** (**"ONT informations"** — kandidat
terkuat untuk "melihat semua ONT di PON" / "status satu ONT", **level-2 BELUM diuji** karena bug skrip,
lihat di bawah), `ont-multicast`, **`ont-optical`** (**"Ont optical."** — kandidat untuk data
optik per-ONT, level-2 belum diuji), `ont-port-vlan`, `ont-remoteconfig`, `ont-softimage`,
`ont-upgrade-status`, `ont-version`, `ont-wanconfig`, `ont-wificonfig`, `ont-wlan-clients`,
`ont-wlanconfig`, `optical-state`, `perf-history`, `perf-monitor`, `perf-ont` (`<cr>`, TERUJI level-2),
`perf-setting`, `port`, `running-config`, `splitter`, `state`, `statistic`, `storm-control`, `vlan`.

### `ont ?` (top-level node, TERUJI — 24 sub-command)

`activate`, **`add`** (level-2 TERUJI: `<0-127>` ONU ID / `loid-auth` / `loid-pass` /
`password-auth` / **`sn-auth`** — **4 metode otorisasi tersedia**, config real cuma memakai `sn-auth`),
**`authorize`** (level-2 TERUJI: `auto` ONT Auto mode / `manual` ONT Manual mode — persis 2 command
yang terlihat di config `ont authorize auto ...`), `catv`, `deactivate`, `delete`, `force-state`,
`igmp-upstream`, `ipconfig`, `modify`, `multicast-downstream`, `opm-alarm-threshold`, `port`, `reboot`,
`restore-config`, **`setting`** (level-2 TERUJI: `<0-127>` ONU ID — nama/desc di level LEBIH DALAM,
belum diuji sesuai batasan sesi ini), `sipconfig`, **`tr069-profile`** (level-2 TERUJI: `<0-127>` ONT-ID
/ `all` All onts in the port — bisa diterapkan ke satu ONT atau SEMUA ONT di port sekaligus), `traffic`,
`upgrade`, `upgrade-multi`, `wan-remoteconfig`, `wanconfig`, `wificonfig`, `wlanconfig`,
`wlanconfig-admin`.

### `ont-autofind` vs `port ont-autofind` — dua command BERBEDA (TERUJI)

- **`ont-autofind ?`** (top-level node) → hanya `delete` ("Delete ONT(s)") — mengelola/membersihkan ONT
  yang sudah TERDETEKSI autofind (tapi mungkin belum diotorisasi).
- **`port ont-autofind ?`** → `disable` / `enable` — kontrol ON/OFF fitur autofind itu sendiri (persis
  `port ont-autofind enable` yang terlihat di config).

### 1. Sintaks nama/desc `ont setting`

Level-1 TERUJI: `ont setting <0-127>` (ONU ID). **Level lebih dalam (`name "..."`, `desc "..."`, batas
panjang/karakter) BELUM diuji dari bantuan resmi** — sesuai batasan sesi ini (hanya sampai `ont setting
?`). Dari observasi config (Tugas 2): bentuk lengkapnya `ont setting <id> name "<Nama - Nomor>" [desc
"<Lokasi>"]`, satu baris terpisah dari `ont add`.

### 2. Command untuk melihat ONT baru/status/daftar

- **(a) ONT baru yang menunggu/autofind**: **`show ont-autofind`** (bare, `<cr>` TERUJI dari bantuan) —
  kandidat kuat, kemungkinan besar menampilkan daftar ONT yang terdeteksi fisik tapi belum diotorisasi.
  **Belum dieksekusi** (hanya level bantuan sesuai batasan sesi).
- **(b) status satu ONT berdasarkan SN**: **[TIDAK BISA DIVERIFIKASI level-2]** — `show ont-info`
  (kandidat terkuat) belum sempat di-`show ont-info ?` karena bug skrip (lihat di bawah). `show ont ?`
  yang SUDAH diuji hanya per-ONT-ID (`eth-port-state`/`ipconfig`/dst), bukan pencarian by-SN.
- **(c) semua ONT di PON**: **[TIDAK BISA DIVERIFIKASI level-2]** — kandidat terkuat tetap `show
  ont-info` (pola nama identik dengan `show onu-info all` E04ID) — belum diuji lebih dalam karena bug
  yang sama.

### 3. Apakah autofind/authorize otomatis menerapkan line/srv/tr069-profile ke ONT baru?

- **Line-profile & srv-profile: YA, TERKONFIRMASI langsung dari SINTAKS command itu sendiri** —
  `ont authorize auto ont-lineprofile-id <N> ont-srvprofile-id <N>` (dilihat di config Tugas 2 & sintaks
  `ont authorize ?` di sesi ini) **MEWAJIBKAN** menentukan line-profile+srv-profile SEBAGAI BAGIAN dari
  command mode-`auto` itu sendiri — bukan langkah terpisah. Device ini punya `ont-lineprofile-id 1` +
  `ont-srvprofile-id 0` terpasang di KEDUA PON.
- **TR069-profile: KEMUNGKINAN BESAR otomatis via WARISAN line-profile, tapi TIDAK 100% pasti** —
  `ont-lineprofile gpon profile-id 1 profile-name "tr069"` sendiri SUDAH mendeklarasikan `tr069-profile
  profile-id 1` DI DALAM definisinya (lihat Tugas 2, blok line-profile). **TAPI** config real menunjukkan
  device/operator TETAP menambahkan baris eksplisit `ont tr069-profile <id> profile-id 1` untuk **171
  dari 171** ONT yang terikat — konsisten 100%, tanpa satu pun pengecualian. **[TIDAK BISA
  DIVERIFIKASI]**: apakah baris eksplisit ini genuinely WAJIB (warisan line-profile TIDAK cukup sendiri),
  atau ini cuma kebiasaan/redundansi operator (warisan sebenarnya sudah cukup). Perlu observasi live
  (ONT baru yang auto-authorize TANPA baris `ont tr069-profile` eksplisit — apakah tetap connect ke
  ACS) untuk memastikan — di luar scope sesi CLI read-only.

### 4. Mekanisme paging/terminal length per sesi

**Terkonfirmasi TIDAK tersedia di dalam node `interface gpon N`** (`terminal ?` → `% There is no matched
command.`) — hanya bisa diakses dari mode `#` root (lihat Tugas 2: `terminal ?` → `length`/`monitor`/
`no`). Tidak ada mekanisme paging terpisah khusus di level node ini.

### ⚠️ Bug skrip riset ditemukan & dilaporkan (transparansi)

Mekanisme auto-discovery skrip (`AUTOSHOW`, dipakai untuk otomatis melanjutkan `show <sub> ?` dari hasil
`show ?`) **mengambil sumber teks yang SALAH** — variabel penampung teks (`show_text`) ternyata berisi
salinan STALE dari HASIL BANTUAN SEBELUMNYA (daftar command bare `?` node ini), BUKAN respons `show ?`
yang sesungguhnya, meski **transkrip live (yang benar-benar dikirim device, terverifikasi manual di
atas) tetap akurat dan lengkap**. Akibatnya: kandidat yang otomatis di-follow-up (`black-ont`,
`flow-control`, `ont`, `ont-autofind`, dll — dari daftar SALAH) TIDAK mencakup `ont-info`/`ont-optical`/
`ont-upgrade-status`/`ont-version` (dari daftar `show ?` yang BENAR) — level-2 dari command-command
paling relevan ini **belum sempat diuji**. Ini kemungkinan bug yang sama dengan yang teramati (tapi tidak
tuntas dianalisis) saat query `terminal length ?` di Sesi 4 E04ID. Daftar `show ?` yang dikutip di
dokumen ini SUDAH dikoreksi memakai transkrip live yang benar (diverifikasi manual), bukan output
otomatis yang salah.

## [TIDAK BISA DIVERIFIKASI] — ringkasan (diperbarui Sesi 2)

- **`show ont-info ?` / `show ont-optical ?` / `show ont-upgrade-status ?` / `show ont-version ?`** —
  belum diuji level-2 (lihat catatan bug skrip). Ini yang paling penting untuk pertanyaan "lihat semua
  ONT"/"status satu ONT" — kandidat proposal Sesi 3 di bawah.
- Apakah `ont add` genuinely otomatis terbentuk dari `authorize auto`+`autofind enable` (belum berubah
  dari Sesi 1).
- Apakah baris eksplisit `ont tr069-profile <id> profile-id 1` per-ONT genuinely WAJIB atau cuma
  redundansi terhadap warisan line-profile.
- Sintaks lengkap `ont setting <id> name "..." desc "..."` beserta batas panjang — masih hanya dari
  observasi config, belum dari bantuan resmi level-2.
- `show black-ont` (bare) — kandidat kuat "daftar ONT ditolak/blacklist", belum dieksekusi.

## Sesi 3 (G02ID) — SELESAI (2026-09-21)

**Login**: satu percobaan, berhasil (`who`: 2 vty, root = sesi kami). `SESSION_END_CLEAN`.
**Bug capture buffer (Sesi 2) SUDAH DIPERBAIKI** sebelum sesi ini — mekanisme baru
(`capture_query_response`, berbasis `log_file` Tcl/Expect, menulis ke file di `/tmp` container lalu
dibaca balik) menggantikan capture berbasis `expect_out(buffer)` yang terbukti tidak andal. Diverifikasi
bekerja benar di sesi ini: setiap query menghasilkan teks yang GENUINELY berbeda dan sesuai
command-nya masing-masing (tidak ada duplikasi/stale seperti Sesi 2).

### `show ont-info ?` / `show ont-optical ?` / `show ont-upgrade-status ?` / `show ont-version ?` (level-2, TERUJI)

| Command | Argumen (dari bantuan) |
|---|---|
| `show ont-info ?` | `<0-127>` / `all` / `name` / `sn` — **4 mode filter** (lihat catatan label di bawah) |
| `show ont-optical ?` | `<0-127>` (ONU ID) / `all` (All ont) / `gpon` (Gpon port) |
| `show ont-upgrade-status ?` | `<cr>` — bare saja, tanpa filter di level ini |
| `show ont-version ?` | `<0-127>` (ONU ID) / `all` (All ont of specified PON Port) |

**Catatan label `show ont-info ?` — kemungkinan bug tampilan firmware (device sendiri), bukan salah
baca kami**: teks bantuan device menampilkan `<0-127>` berdeskripsi "All onts in the port" dan `all`
berdeskripsi "ONU ID. <0-127>" — **deskripsi tampak tertukar/bergeser satu baris**, pola SAMA seperti
yang sudah teramati di E04ID (`show onu-upgrade-status ?`). **Fakta yang tetap jelas dan pasti**:
`show ont-info` mendukung **4 cara filter — per ID, semua (`all`), by `name`, by `sn` (Serial
Number)** — kemungkinan besar **`show ont-info sn <SN>` = jawaban langsung untuk "status satu ONT
berdasarkan SN"**, dan **`show ont-info all` = jawaban langsung untuk "semua ONT di PON"**. **Level-3**
(`show ont-info sn ?`, `show ont-info all ?`, dst — untuk konfirmasi urutan argumen persis) **belum
diuji** — di luar cakupan script yang disetujui sesi ini, kandidat untuk sesi berikutnya kalau
diinginkan.

### `ont tr069-profile ?` — 2 level (TERUJI)

```
ont tr069-profile <0-127|all> profile-id <0-2047>
ont tr069-profile <0-127|all> profile-name <nama>
```
Bisa diterapkan ke SATU ONT (`<0-127>`) atau SEMUA ONT di port (`all`) sekaligus, dipilih lewat
`profile-id` (numerik) ATAU `profile-name` (nama) — dua cara merujuk profile yang sama.

### `ont setting ?` — 2 level (TERUJI, mengonfirmasi RESMI sintaks `name`/`desc`)

```
ont setting <0-127> desc "<teks>"
ont setting <0-127> name "<teks>"
```
**Ini konfirmasi RESMI pertama (dari bantuan CLI, bukan cuma observasi config) bahwa `ont setting`
punya persis 2 field: `desc` (Ont description) dan `name` (Ont name)** — cocok 100% dengan pola yang
sudah teramati di `show startup-config`. **Batas panjang/karakter untuk isi `desc`/`name` masih belum
tampil** di level ini — perlu `ont setting 0 desc ?` / `ont setting 0 name ?` (satu level lagi) untuk
memastikan, belum dilakukan sesi ini.

### `ont add <id> sn-auth ?` — 2 level (TERUJI) — sintaks lengkap pendaftaran ONT manual

```
ont add <0-127> sn-auth <SN-VALUE> omci ...
```
- **`SN-VALUE`**: dari bantuan resmi — *"Length<12-16> ONT serial number, format harus salah satu dari:
  `<XXXXXXXXXXXX>` (12 char), `<XXXXXXXXXXXXXX>` (14 char), `<XXXXXXXXXXXXXXXX>` (16 char), atau
  `<XXXX-XXXXXXXX>` (format berdash).** Konsisten dengan pola 16-karakter hex yang teramati di config
  Tugas 2 (mis. format umum ONT serial number industri).
- Setelah `sn-auth <SN-VALUE>`, kata kunci wajib berikutnya: **`omci`** (Omci configuration) — konsisten
  dengan pola `ont add <id> sn-auth <hex> omci ont-lineprofile-id <N> ont-srvprofile-id <N>` yang sudah
  teramati di config nyata (kelanjutan setelah `omci` — `ont-lineprofile-id`/`ont-srvprofile-id` — **dari
  observasi config, BUKAN dari bantuan resmi level lebih dalam**, belum di-query lebih jauh sesi ini).
- **TIDAK PERNAH dieksekusi** — semua di atas murni dari rantai bantuan `?`, ID `0`/SN dummy
  `0000000000000000` hanya placeholder untuk memicu bantuan level berikutnya, bukan operasi nyata.

### Eksekusi nyata TERUJI (izin khusus, read-only, bentuk kosong) — hasil KOSONG, aman dilaporkan apa adanya

- **`show ont-autofind`** (bare) → *"Warning, The automatically found ONTs do not exist."* — **0
  entri**. Saat ini **tidak ada ONT yang terdeteksi autofind menunggu otorisasi** di device ini. Tidak
  ada kolom/data untuk ditampilkan (list genuinely kosong).
- **`show black-ont`** (bare) → *"There's not found ont in ont deny list."* — **0 entri**. Tidak ada ONT
  di deny-list/blacklist saat ini. Tidak ada kolom/data untuk ditampilkan.
- **Tidak ada data ONT nyata (SN/MAC/nama) yang muncul di kedua output ini** — keduanya genuinely kosong,
  jadi tidak ada yang perlu di-mask.

## Jawaban tambahan laporan

**(1) Apakah `ont authorize auto` (dari config global yang sudah dilihat, Tugas 2) memuat referensi
`tr069-profile`?** **TIDAK.** Baris konfigurasi nyata yang teramati: `ont authorize auto
ont-lineprofile-id 1 ont-srvprofile-id 0` — hanya menyebut `ont-lineprofile-id` dan `ont-srvprofile-id`,
**tidak ada kata "tr069-profile" sama sekali** di baris ini. Binding TR069 selalu berupa baris TERPISAH
per-ONT (`ont tr069-profile <id> profile-id 1`), tidak pernah menyatu dengan command `authorize`.

**(2) Sintaks lengkap mendaftarkan ONT baru manual (dari bantuan, referensi saja — TIDAK dieksekusi)**:
```
ont add <0-127> sn-auth <SN-VALUE, 12/14/16-char hex atau format XXXX-XXXXXXXX> omci
  ont-lineprofile-id <N>       (dari observasi config — belum di-query dari bantuan lebih dalam)
  ont-srvprofile-id <N>        (dari observasi config — belum di-query dari bantuan lebih dalam)
```
Bagian `SN-VALUE` dan `omci` sudah TERKONFIRMASI RESMI dari bantuan CLI sesi ini; bagian
`ont-lineprofile-id`/`ont-srvprofile-id` masih dari observasi config lama (Tugas 2), belum
dikonfirmasi ulang lewat rantai bantuan yang lebih dalam (`ont add 0 sn-auth <SN> omci ?`).

## [TIDAK BISA DIVERIFIKASI] — ringkasan (diperbarui Sesi 3)

- Urutan/sintaks persis `show ont-info sn <SN> ?` dan `show ont-info all ?` (level-3) — kandidat kuat
  untuk 2(b)/2(c) tapi belum diuji sampai kedalaman itu.
- Batas panjang/karakter `ont setting <id> desc "..."` / `name "..."` — field-nya sudah terkonfirmasi
  resmi, tapi batasnya belum (perlu `ont setting 0 desc ?` / `ont setting 0 name ?`).
- Kelanjutan bantuan setelah `ont add <id> sn-auth <SN> omci ?` (apakah `ont-lineprofile-id`/
  `ont-srvprofile-id` genuinely muncul di bantuan, dan urutan/format persisnya).
- Apakah baris eksplisit `ont tr069-profile <id> profile-id 1` per-ONT genuinely wajib atau redundan
  terhadap warisan line-profile (belum berubah dari Sesi 2).
