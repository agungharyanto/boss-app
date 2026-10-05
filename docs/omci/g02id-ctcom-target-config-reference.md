# Konfigurasi Target G02ID CT-COM — Referensi Final

> **Terverifikasi dari device NYATA (bukan tebakan), 2026-10-03.** Sumber: ONT uji
> CMDC **H3-2S XPON** (CT-COM), GenieACS `_id = 5C75C6-H3%2D2S%20XPON-<serial>`, di OLT
> `HSGQ-G02ID-BUMIREJA` (olt_devices id=4), PON 1 onu 28. Semua nilai dibaca via
> GenieACS/TR-069 setelah Agung mengatur kondisi final secara manual di GUI modem +
> `refreshObject` penuh (tree s/d ~1300 param). Dummy PPPoE user `TEST-OMCI-G02ID-DAHLIA`.
> Data pelanggan di-mask; dokumen ini fokus struktur/path config, bukan PII.

Dokumen ini menjadi acuan desain **`G02idActivationService::pushNetworkPolicy()`** (belum
dikode — lihat "Implikasi desain" di bawah). Arsitektur G02ID sudah ditetapkan:
**WAN = TR-069 (device-driven CT-COM), penamaan = OMCI (`ont setting name`)**.

## 4 aturan bisnis G02ID CT-COM → path + nilai target (terverifikasi)

| # | Aturan | TR-069 path (resolve LIVE, lihat catatan WCD dinamis) | Nilai target | xsd |
|---|---|---|---|---|
| 1 | **NAT off** pada WAN internet VLAN 10 | `InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{N}.WANPPPConnection.1.NATEnabled` | `false` | boolean |
| 2 | **DHCP Server off** (LAN) saat pakai WAN VLAN 10 | `InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.DHCPServerEnable` | `false` | boolean |
| 3 | **WAN Bridge VLAN 172 ADA** | `...WANConnectionDevice.{M}.WANPPPConnection.1` dengan `Name` `*_B_VID_172`, `ConnectionType=PPPoE_Bridged`, `NATEnabled=false` | ada + Connected | — |
| 4 | **SSID4 & SSID8 enabled + open** (tanpa password) | **TIDAK DAPAT via TR-069** — lihat temuan kritis #2 | (domain GUI/OMCI) | — |

Nilai final terverifikasi di ONT uji: NAT (WAN VID10) = `false`, DHCPServerEnable = `false`,
bridge VID172 = ada (PPPoE_Bridged, Connected), SSID4/8 = diset di GUI tapi invisible di TR-069.

## ⚠️ Temuan KRITIS untuk desain `pushNetworkPolicy()` (berbasis bukti, bukan asumsi)

### 1. WCD instance CT-COM DINAMIS — renumber + bounce sesi pada SETIAP perubahan config
`WANConnectionDevice.{N}` **tidak stabil**. Pada ONT uji ini, nomor WCD untuk WAN internet VID10
berubah beberapa kali (`WCD.3`→`WCD.5`→`WCD.7`) dan nama service-nya ikut bergeser
(`3_INTERNET_R_VID_10` → `5_Other_R_VID_10`) **setiap kali ada perubahan config** — baik push
BOSS (NAT/DHCP/username) MAUPUN klik manual GUI. Tiap reorg juga **mem-bounce sesi PPPoE**
(radacct `acctstarttime` baru + IP baru tiap kali; sesi putus-sambung singkat, bukan outage
permanen). Konsekuensi desain:
- **JANGAN hardcode nomor WCD.** Selalu resolve WAN target by **VLANIDMark** / **Name** (`*VID_10*`,
  `*_B_VID_172*`), bukan index tetap. (Pola `CpeParameterResolverService::findActivePppConnection()`
  yang sudah ada — skip bridged, cari by Username — adalah contoh resolusi dinamis yang benar.)
- **Setiap push NAT/DHCP akan memicu reorg + bounce.** Ini EFEK SAMPING NYATA, bukan operasi senyap
  (koreksi atas klaim awal "nol gangguan" saat uji Dahlia — sesi NYATA bounce tiap toggle). Desain
  harus memperlakukan `pushNetworkPolicy()` sebagai operasi disruptif-singkat terjadwal, bukan
  silent.
- Verifikasi "Connected" paling andal lewat **`radacct` (Access-Accept + sesi aktif + IP)**, BUKAN
  GenieACS `ConnectionStatus` (yang lagging; sempat "Connecting" padahal radacct sudah aktif).

### 2. SSID4/8 TIDAK dapat dikelola via TR-069 pada CMDC H3-2S
GenieACS/TR-069 device ini **hanya mengekspos `WLANConfiguration.1` (2.4G primer) dan `.5` (5G
primer)** — instance **4 & 8 TIDAK PERNAH muncul** meski: (a) Agung mengaktifkan+mengonfigurasi
SSID4/8 di GUI lokal, (b) `refreshObject` per-subtree DAN dari ROOT `InternetGatewayDevice`
(tree s/d 1343 param), (c) polling 5+ menit lintas banyak Inform. Device genuinely tidak melaporkan
4/8 di data model TR-069-nya. Konsekuensi:
- **BOSS tidak bisa push SSID4/8 open via TR-069.** Aturan #4 harus ditangani jalur lain
  (GUI lokal / OMCI), BUKAN `pushNetworkPolicy()` TR-069.
- **Penentuan apakah aturan SSID4/8 berlaku → pakai field "Tipe Modem" (`cpe_devices`, badge
  auto-terdeteksi)**, BUKAN deteksi runtime `show ont-capability`/tree GenieACS (keduanya tak andal:
  `show ont-capability 28` bahkan melaporkan `WLAN Instance 0`, keliru). Field Tipe Modem terstruktur
  & lebih dapat dipercaya.
- Param security WLAN yang TR-069 BISA lihat (instance 1/5), untuk referensi "open": `BeaconType`,
  `BasicEncryptionModes`, `WPAEncryptionModes`, `IEEE11iEncryptionModes`, `BasicAuthenticationMode`,
  `WPAAuthenticationMode`, `IEEE11iAuthenticationMode`, `KeyPassphrase`, `PreSharedKey.1.PreSharedKey`.
  (Pada ONT uji: instance 1/5 = `Enable=false`, `KeyPassphrase` kosong, SSID bawaan device bekas.)

### 3. WAN CT-COM device-driven dari firmware, BUKAN template BOSS
`wan_config_templates` = **0 baris** di DB, dan modelnya pun hanya mencakup `wan1/wan2`
PPPoE-username/password/VLAN — **tak ada field NAT/DHCP/bridge/SSID**. Jadi WAN semua pelanggan CT-COM
(termasuk uji ini) terbentuk dari **template firmware CT-COM di CPE sendiri** (`2_*_B_VID_172`
dst = template ISP standar), bukan push BOSS. `pushNetworkPolicy()` kalau dibangun harus pakai
`setParameterValues` terstruktur (resolve path live) — BUKAN reuse `WanConfigTemplate` yang tak cukup.

## Struktur WAN final ONT uji (snapshot, nomor WCD DINAMIS — jangan diandalkan sebagai konstan)

| WCD | Name | VLAN | Type | Status | NAT |
|---|---|---|---|---|---|
| 1 | (kosong) | 0 | — | — | — |
| 2 | `1_TR069_R_VID_9` | 9 | IP_Routed | Connected | false |
| 4 | `omci_ipv4_dhcp_1` | 9 | IP_Routed | Connected | true |
| 6 | `4_INTERNET_B_VID_172` (bridge) | 172 | PPPoE_Bridged | Connected | false |
| 7 | `5_Other_R_VID_10` (internet VID10) | 10 | IP_Routed | Connected | **false** ✅ |

## Mekanisme discovery yang dipakai/terbukti
- **`refreshObject` (NBI sendTask)** — discovery non-mutating; refresh objek yang device SUDAH ekspos.
  TIDAK meng-enumerasi instance tersembunyi (WLAN4/8 tetap tak muncul walau root refresh).
- **`declare("${path}.*", null, {path:1})` + commit** (GenieACS provision, mis. `default-wan.js`) —
  satu-satunya mekanisme yang ENUMERASI instance CT-COM dinamis, tapi server-side di preset, bukan
  task NBI one-off. (NBI sendTask hanya: get/set ParameterValues, refreshObject, add/deleteObject,
  reboot, factoryReset — tak ada getParameterNames.)
- GenieACS device `_id` HARUS bentuk **URL-encoded** (`...H3%2D2S%20XPON...`); bentuk ter-decode →
  query NULL.

## Model DUA-SUMBER WAN — terverifikasi di hardware (2026-10-05, onu 28)

> Investigasi read-only SSH ke OLT G02ID (`show ont ipconfig 28` / `show ont-wanconfig 28 all`) +
> cross-check GenieACS. Dipicu kebingungan: setelah Agung hapus WAN VID10 di GUI, WAN manajemen VID9
> (`omci_ipv4_dhcp_1` + `1_TR069_R_VID_9`) **selamat** sementara WAN internet VID10 + bridge VID172
> **hilang permanen**. Ternyata keduanya dari SUMBER BERBEDA:

| WAN | Sumber | Mekanisme | Bukti |
|---|---|---|---|
| **Manajemen VID9** (`omci_ipv4_dhcp_1`, `1_TR069_R_VID_9`) | **OMCI / OLT-driven** | Line-profile `tr069` (profile-id 1) yang ter-bind otomatis saat `ont authorize auto` punya `tr069-mode enable 0 **dhcp enable mgmtvlan 9**` → OLT membuat **OMCI IP Host** di ONT | `show ont ipconfig 28` → IP Host index 0: VLAN 9, Type **DHCP**, IP **10.1.3.172**. GenieACS `omci_ipv4_dhcp_1.ExternalIPAddress` = **10.1.3.172** (IDENTIK). `ont-info 28`: Line Profile "tr069" + Srv Profile "srvprofile_default_0" ter-bind. |
| **Internet VID10 (PPPoE) + bridge VID172** | **TR-069 / firmware CT-COM-driven** | Dibentuk firmware CPE / provisioning ACS, **one-time** (saat factory-default / Inform awal) | `show ont-wanconfig 28 all` → **"There's not configure any wan interface"** (KOSONG, dikonfirmasi 3×). Tidak ada jejak VID10/172 di sisi OLT sama sekali. |

**Konsekuensi operasional (KRITIS untuk runbook aktivasi):**
- WAN manajemen VID9 **re-apply otomatis oleh OLT/OMCI** tiap authorize → tahan terhadap hapus-di-GUI.
- WAN internet VID10 + bridge VID172 **one-time firmware** → sekali dihapus di GUI, **TIDAK kembali
  sendiri**. Tidak ada mekanisme OLT yang membentuknya ulang.
- **Prasyarat `G02idActivationService::activate()` / `pushNetworkPolicy()`**: CPE harus dalam kondisi
  **firmware-default WAN** (VID10 + VID172 sudah terbentuk) — `pushNetworkPolicy` me-*resolve* WAN VID10
  existing untuk push credential, BUKAN membuatnya. Pada ONT yang baru dipasang teknisi (factory-default)
  ini terpenuhi otomatis. Pemulihan ONT yang WAN-nya sudah terhapus manual = **factory reset** (firmware
  bentuk ulang) ATAU buat ulang WAN di GUI — keputusan operator, di luar kode `activate()`.
- `show ont-remoteconfig 28` hanya toggle manajemen remote (Telnet/Http/Ping), **bukan** WAN service —
  tidak relevan dengan VID10/172.

## Multi-SSID via AddObject — TERBUKTI BEKERJA (mengoreksi kesimpulan sebelumnya) (2026-10-06)

> Diverifikasi end-to-end ke device NYATA Dahlia (CMDC H3-2S XPON, fw V1.1.20P1T4, GenieACS
> `5C75C6-H3%2D2S%20XPON-CMDCA200BB76`). Semua via TR-069/GenieACS dari BOSS App — NOL GUI manual.

### 1. KESIMPULAN LAMA **SALAH**
Catatan sebelumnya "SSID4/8 mustahil via TR-069" (disimpulkan dari 5 metode: GET/SET langsung, OMCI-show,
device-page summon, blind-write, export device teman) **KELIRU**. Akar kekeliruan: **SEMUA metode itu
memakai `SetParameterValues` ke instance yang BELUM ADA**. Menulis ke objek TR-069 yang belum terbentuk =
**silent no-op** (device tidak fault 9005, tapi juga tidak membuat/menerima apa pun) — ini BUKAN bukti
device tak mendukung, hanya bukti RPC-nya salah.

### 2. METODE YANG BENAR — `AddObject` ke PARENT COLLECTION
`sendTask` task type **`addObject`** dengan `objectName = "InternetGatewayDevice.LANDevice.1.WLANConfiguration"`
(**TANPA nomor instance di akhir**). Device **meng-assign nomor instance SENDIRI** — tidak bisa diminta
nomor spesifik. Sering **Stale/Pending di percobaan pertama**, berhasil di Inform berikutnya — **retry
beberapa kali dengan jeda wajar** (pola normal, bukan error).

### 3. POLA PENOMORAN — SEQUENTIAL per grup band
Device mengisi **grup 2.4G penuh dulu (instance 1→2→3→4)** SEBELUM mulai grup **5G (5→6→7→8)**. Tidak bisa
loncat ke instance manapun; AddObject berturut-turut mengisi nomor berikutnya dalam grup yang sedang aktif.
`X_CT-COM_RFBand`: `0`=2.4G, `1`=5G (terisi setelah Inform; instance baru sering `null` dulu).

### 4. RESEP "OPEN" (tanpa password) — PERSIS untuk CMDC H3-2S fw V1.1.20P1T4
`SetParameterValues` ke instance yang SUDAH dibuat AddObject:
```
BeaconType           = "None"
WEPEncryptionLevel   = "Disabled"      ← field yang DULU TERLEWAT; tanpa ini WEP "40-bit" nyangkut
BasicEncryptionModes = "None"
KeyPassphrase        = ""              (+ PreSharedKey.1.PreSharedKey = "" bila ada)
SSID                 = "<nama>"  · Enable = true
```
**JANGAN set `BasicAuthenticationMode="None"`** → device **TOLAK, fault `cwmp.9007` (Invalid parameter
value)**; enum non-standar, device hanya terima `"Both"`. Karena `setParameterValues` ATOMIK, satu nilai
invalid me-rollback SELURUH task — makanya field ini harus di-EXCLUDE. `"Both"` inert selama
`BeaconType="None"` (beacon tak mengiklankan security apa pun = genuinely open). Field residual
`IEEE11i*`/`WPA*` juga inert di bawah `BeaconType="None"`.

### 5. HASIL AKHIR Dahlia
`instance 4` (2.4G) + `instance 8` (5G) = SSID **"TOKEN WIFI"**, open, enabled — **persis target binding
preset** `X_CT-COM_LanInterface = WLANConfiguration.4,.8` (WAN2 bridge VID172 → SSID4+SSID8). Rantai token
WiFi kini koheren & fully-automated.

### 6. IMPLIKASI DESAIN BAGIAN B (Zero-Touch)
Mekanisme SSID token WiFi **SEKARANG bisa fully-automated dari BOSS App** — **menggantikan asumsi lama
"harus GUI manual"**. Pola: loop `AddObject` (sampai dapat instance target per grup band — mis. ulangi
sampai nomor 4 untuk 2.4G, 8 untuk 5G) → `SetParameterValues` resep open (#4). Ini jadi bagian inti desain
zero-touch provisioning CT-COM.

### 7. Sisa cleanup — BELUM dieksekusi (bukan dihapus dari dokumen)
Proses AddObject meninggalkan instance perantara di Dahlia yang belum dibereskan:
- `instance 2` (2.4G) — "TOKEN WIFI" open **ENABLED (duplikat dari 4)** — perlu `Enable=false`.
- `instance 3` (2.4G), `instance 6` & `7` (5G) — SSID default kosong, disabled — harmless, bisa
  dinonaktifkan/dibiarkan.
- `instance 1` & `5` (Susi salon asli) — TIDAK disentuh.
