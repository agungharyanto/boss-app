# Zero-Touch Provisioning (ZTP) — Dynamic Config Design (v0.23.8)

**Status: DESAIN untuk review Agung. BELUM ada migration/kode.** Dokumen ini hasil LANGKAH 0 (katalog +
audit read-only) + usulan arsitektur dua-lapis (Adapter + Template) sesuai arahan Agung. Setelah di-review
& disetujui, implementasi dipecah ke sub-versi berikutnya.

Tujuan: menggantikan hardcode per-aktivasi (bukti nyata: v0.23.6 G02ID vs v0.23.7 E04ID punya VLAN/NAT/
binding/SSID BEDA-BEDA — lihat `hsgq-e04id-cli-reference.md` rule #11) dengan sistem konfigurasi dinamis:
**TEMPLATE** (apa yang di-provision, vendor-agnostic) × **ADAPTER** (cara men-push ke hardware tertentu) ×
**identitas pelanggan** (cid → username/SSID).

---

## A. KATALOG PARAMETER (per vendor, UNIVERSAL vs VENDOR-SPECIFIC)

Sumber: `default-wan.js` (4 data-model TR-069), `G02idActivationService`, `zte-c300-cli-reference.md`,
`hsgq-g02id-cli-reference.md`, `hsgq-e04id-cli-reference.md`, saga aktivasi v0.23.6/v0.23.7.

**Enam "jalur" nyata** (vendor × transport):
1. **ZTE C300** — OMCI/CLI murni (telnet), TIDAK ada TR-069 WAN push.
2. **Huawei** — TR-069 (`X_HW_*`).
3. **CMCC** — TR-069 (`X_CMCC_*`).
4. **ZTE-generic** (F6xx TR-069) — TR-069 (`X_ZTE-COM_*`).
5. **CT-COM GPON** (mis. HSGQ-G02ID) — TR-069 (`X_CT-COM_*`, objek VLAN `X_CT-COM_WANGponLinkConfig`) + OMCI naming + save CLI.
6. **CT-COM EPON** (mis. HSGQ-E04ID) — sama CT-COM TAPI objek VLAN `X_CT-COM_WANEponLinkConfig` + admisi `bind-onu`/`onu confirm` + save CLI.

### Tabel konsep UNIVERSAL (konsep sama lintas vendor, field beda)

| Konsep | ZTE C300 (OMCI/CLI) | Huawei TR-069 | CMCC TR-069 | ZTE-generic TR-069 | CT-COM GPON (G02ID) | CT-COM EPON (E04ID) |
|---|---|---|---|---|---|---|
| **WAN VLAN internet** | `service-port N vport 1 user-vlan <V> vlan <V>` + `flow 1 pri 0 vlan <V>` | `X_HW_VLAN` | `X_CMCC_VLANIDMark` + `X_CMCC_VLANMode=2` | `X_ZTE-COM_VLANID` + `X_ZTE-COM_VLANEnable=true` | `WCD.N.X_CT-COM_WANGponLinkConfig.{VLANIDMark,Mode=2,Enable}` (level-WCD) | `WCD.N.X_CT-COM_WANEponLinkConfig.{VLANIDMark,Mode=2,Enable}` (level-WCD) |
| **ConnectionType (routed)** | (PPPoE sisi-client; OLT cuma bridge VLAN) | `IP_Routed` | `PPPoE_Routed` | `PPPoE_Routed` | `IP_Routed` | `IP_Routed` |
| **PPPoE cred** | — (auth via RADIUS) | `Username`/`Password` | `Username`/`Password` | `Username`/`Password` | `Username`/`Password` | `Username`/`Password` |
| **NAT on/off** | (n/a di OLT) | `NATEnabled` | `NATEnabled` | `NATEnabled` | `NATEnabled` | `NATEnabled` |
| **WAN Bridge (VLAN kedua)** | `service-port 12 ... vlan 172` | `WANIPConnection.2` `IP_Bridged` | `WANPPPConnection.2` `PPPoE_Bridged` | `PPPoE_Bridged` | WCD terpisah `PPPoE_Bridged` | WCD terpisah `PPPoE_Bridged` |
| **Binding WiFi/LAN** | `switchport-bind switch_0/1 {iphost,veip}` | `X_HW_LANBIND.SSIDnEnable` (flag per-SSID) | `X_CMCC_LanInterface` (CSV path WLANConfiguration) | `X_ZTE-COM_LanInterface` (CSV) | `X_CT-COM_LanInterface` (CSV) + `-DHCPEnable` | sama CT-COM |
| **ServiceList tag** | (implisit service-port) | — | `X_CMCC_ServiceList=INTERNET` | `X_ZTE-COM_ServiceList` | `X_CT-COM_ServiceList=INTERNET` | sama |
| **DHCP LAN server** | (n/a di OLT) | `LANHostConfigManagement.DHCPServerEnable` | sama | sama | sama | sama |
| **SSID (nama/pass/security)** | (domain GUI/OMCI — bukan jalur OLT) | `WLANConfiguration.n.{SSID,KeyPassphrase,BeaconType,...}` | sama | sama | sama + **AddObject** utk instance 4/8 | sama + **AddObject** |
| **Penamaan ONU/ONT** | `interface gpon-onu ... name/description` | OMCI `ont setting name` | — | — | OMCI sidecar `set_ont_naming "Nama - CID"` | CLI `onu description <id> "Nama - CID"` (+ `name` di bind-onu) |

### Tabel konsep VENDOR-SPECIFIC (hanya ada di satu/sebagian vendor)

| Konsep | Vendor | Catatan |
|---|---|---|
| **Admisi deny-by-default** (`bind-onu mac <MAC>` → `onu confirm onu-id <N>` di `interface epon <PON>`) | **E04ID (CT-COM EPON) saja** | G02ID auto-authorize; ZTE pakai `uncfg`→config. **Ini pembeda admisi terbesar antar OLT.** |
| **Objek VLAN beda GPON vs EPON** (`X_CT-COM_WANGponLinkConfig` vs `X_CT-COM_WANEponLinkConfig`) | **CT-COM** | Sub-mode adapter CT-COM; sudah dipecahkan `ctcVlanConfigObject()` (default-wan.js, v0.23.7). |
| **Model tcont/gemport/service-port/flow** | **ZTE C300 saja** | `tcont 1 profile <paket>`, `gemport 1 tcont 1`, `gemport 1 traffic-limit downstream <profil>`, `service-port N vport 1 user-vlan/vlan`, `flow mode/flow pri vlan`, `vlan-filter iphost`. Nama profil tcont sudah selaras `PppPackage` BOSS App. |
| **TR-069 mgmt via `security-mgmt 999 protocol ... tr069`** (bukan `tr069-mgmt` per-ONU) | **ZTE C300** | Template tetap, bukan per-pelanggan; ACS URL kemungkinan via DHCP Option 43/line-profile, bukan per-ONU. |
| **`switchport-bind ... iphost/veip`** | ZTE C300 | Binding layanan ke uplink; template tetap. |
| **`X_HW_LANBIND.SSIDnEnable` (flag per-SSID), bukan CSV path** | Huawei | Mekanisme binding beda dari CMCC/ZTE/CT-COM yang pakai CSV `X_*_LanInterface`. |
| **Save eksplisit `copy running-config startup-config`** | **HSGQ G02ID + E04ID** | ZTE C300 auto-write (tidak perlu save). Huawei/CMCC/ZTE-generic (TR-069 murni) persist di device sendiri. |
| **AddObject sekuensial WLANConfiguration (grup band 1→2→3→4, 5→6→7→8)** | CT-COM (teruji CMDC H3-2S) | Instance 4/8 tidak ada default; harus AddObject per-band. Instance number dinamis (jangan hardcode). |
| **WAN mgmt OMCI-driven VID9** | CT-COM (G02ID/E04ID) | WAN TR-069 manajemen muncul otomatis dari OMCI; BOSS tidak push. |

### Variabel per-pelanggan vs tetap/template (dari perbandingan nyata 2 ONU_UJI ZTE + Dahlia/unit-uji CT-COM)
- **Per-pelanggan**: `name`/`description` ONU, PPPoE username (`{cid}@ppp.bajastu.id`) + password, VLAN internet (tergantung paket/NAS), SSID name (bisa `{cid}`), SSID password.
- **Tetap/template**: struktur WAN (jumlah service-port / WCD), profil tcont (per-PAKET, bukan per-pelanggan), traffic-limit, VLAN bridge bersama (172), `security-mgmt`/`switchport-bind`/`flow mode`, resep SSID open (BeaconType=None dst), binding role→SSID-instance.

---

## B. KODE REUSABLE (hasil baca lengkap)

### B1. `App\Services\Network\G02idActivationService` (v0.23.6) — CALON INTI ADAPTER CT-COM
Method matang, sudah teruji produksi:
- `writeRadcheck(Customer)` → derive `{cid}@ppp.bajastu.id` + Framed-Pool/VLAN dari paket (`TestCredentialSyncService::deriveLiveWanParams`), tulis via `RadcheckWriterService`. **UNIVERSAL** (dipakai CT-COM GPON/EPON; ZTE juga RADIUS-based).
- `pushNetworkPolicy(Customer, genieAcsDeviceId)` → resolve WAN PPPoE internet DINAMIS by VLAN (`resolveInternetPppPathByVlan`), push Username/Password/NATEnabled + DHCPServerEnable. **CT-COM-specific** (path TR-069 + `X_CT-COM_WANGponLinkConfig` HARDCODE GPON — lihat ⚠️ di bawah).
- `pollRadacctConnected(username)` → verifikasi sesi `radacct` aktif (lebih akurat dari ConnectionStatus GenieACS). **UNIVERSAL** (RADIUS-based).
- `verifyBridgePresent(genieAcsDeviceId, 172)` → baca bridge VLAN172. **CT-COM-specific + HARDCODE GPON**.
- `applyOmciNaming(OltDevice, Customer, sn)` → `resolveOnuBySn` + `set_ont_naming` via sidecar. **CT-COM GPON** (E04ID pakai `onu description`, beda command).
- `saveConfig(OltDevice)` → sidecar `copy running-config startup-config`. **HSGQ (G02ID+E04ID)**.
- `buildOntName(Customer)` → `"Nama - CID"` trunc 40. **UNIVERSAL**.

**⚠️ UTANG untuk adapter**: `resolveInternetPppPathByVlan()` & `verifyBridgePresent()` masih **hardcode
`X_CT-COM_WANGponLinkConfig`** (GPON) — sama kelas bug yang sudah diperbaiki di `default-wan.js`. Saat
diangkat jadi adapter CT-COM, kedua method ini WAJIB pakai pola `ctcVlanObj` (deteksi GPON/EPON) juga,
supaya `G02idActivationService` bisa melayani E04ID. Saat ini service ini namanya "G02id" tapi alur
intinya 90% universal CT-COM — **rename → `CtcomActivationService` / adapter** saat implementasi.

### B2. `default-wan.js` + `ctcVlanConfigObject()` (v0.23.7) — CONTOH PERTAMA POLA ADAPTER
Struktur cabang `isHuawei`/`isCMCC`/`isZTEGeneric`/`isCTCom` di dalam satu provision = **de-facto adapter
per-vendor** (tiap cabang tahu field VLAN/binding/ServiceList vendor-nya). `ctcVlanConfigObject()` =
contoh pertama "sub-mode" (GPON/EPON auto-detect dari tree, tanpa field DB). **Pola ini yang diformalkan
jadi lapisan ADAPTER.** Helper `ctcResolveWcd`/`ctcNewInstance`/`ctcFreeWcd` (WCD dinamis) + resep SSID
open + AddObject sekuensial = logika CT-COM yang reusable.

### B3. `RemoteWanConfig` (singleton id=1) — HARUS JADI MULTI-ROW/TEMPLATE
Saat ini SATU baris platform-level, di-serialize jadi `args` posisional provision `default-wan`. Kolom:
`enabled, wan1_{enabled,vlan,pppoe_username,pppoe_password,serial_allowlist}, wan2_{enabled,vlan,serial_allowlist}`.
**Keterbatasan**: satu konfigurasi global → tidak bisa beda per paket/pelanggan; `serial_allowlist` dipakai
sebagai scoping fase testing (hack). **ZTP butuh multi-row (template)** + resolusi per-aktivasi, bukan satu
singleton global. → DIPENSIUNKAN/digantikan tabel template baru (B6).

### B4. `OnuRegistryService::SUPPORTED_VENDOR_OPERATIONS` — POLA GATING PER-VENDOR (reusable)
`['zte_c300' => 'onu_detail_info', 'hsgq_g02id' => 'resolve_onu_by_sn']` — map `vendorKey → operasi
teruji`, melempar jika vendor belum didukung. **Pola persis yang dipakai untuk ADAPTER REGISTRY**: map
`vendor(+pon_mode) → handler + kapabilitas`, dengan stub eksplisit "belum didukung". `OltDevice::
sidecarVendorKey()` sudah ada sebagai sumber vendorKey.

### B5. `TestOnuWanConfig` + `TestOnuAttachedVlan` (v0.23.5) — SEBAGIAN DIPENSIUNKAN
Per-customer (test-fixture only): `onu_mode`(routing/bridging), `wan_mode`(pppoe/dhcp/static),
`config_method`(omci/tr069), `vlan_pppoe`, `package_id`, pppoe cred + `attachedVlans` (VLAN9 wajib).
- **Relevan dibawa ke ZTP**: konsep `wan_mode` (pppoe/dhcp/static), `onu_mode` (routing/bridging),
  attached-VLAN list, VLAN9-mandatory (mgmt).
- **Dipensiunkan**: sebagai jalur konfig "test-only" terpisah — ZTP menyatukan test & produksi di satu
  model template. Harness test tetap bisa menandai customer `is_test_fixture` + `TestCredentialSyncService`
  guard, tapi WAN config-nya pindah ke template ZTP.

### B6. `wan_config_templates` (v0.12.5) + `WanConfigTemplateResolverService` — PENDAHULU, PERLUAS/GANTI
Sudah ADA sistem template! Keyed `(ppp_package_id, modem_type_id)` unik, resolve ON-DEMAND
(`resolveTemplateForDevice`: exact match paket+modem_type → fallback default paket). `cpe_devices.
modem_type_id` = sumber kebenaran; **template TIDAK disimpan permanen di device** (prinsip bagus: template
berubah tak perlu migrasi device). Kolom: `name, ppp_package_id, modem_type_id, enabled, wan1_{enabled,
vlan,pppoe_username,pppoe_password}, wan2_{enabled,vlan}`.
- **Prinsip yang DIBAWA**: resolve on-demand, device cuma tahu "paket + tipe modem"-nya, bukan template.
- **Kurang untuk ZTP**: tidak ada SSID, tidak ada WiFi-role, tidak ada wan_mode (pppoe/dhcp/static), tidak
  ada NAT flag, tidak ada bridge-SSID-binding, tidak sadar adapter/pon_mode. Masih pakai `args` posisional
  gaya singleton.
- **Keputusan desain (usul)**: `wan_config_templates` = pendahulu langsung tabel template ZTP. Bisa
  di-**migrasi/ganti** oleh `provisioning_profiles` (B-skema di bawah). `WanConfigTemplateResolverService`'s
  pola resolusi (exact (package,modem_type) → default package) DIPERTAHANKAN sebagai resolver profil ZTP.

### B7. Lain-lain reusable
- `TestCredentialSyncService::deriveLiveWanParams(Customer)` — sumber tunggal username/password/VLAN/
  Framed-Pool dari paket. UNIVERSAL, dipakai lintas adapter.
- `RadcheckWriterService` — tulis radcheck/radreply. UNIVERSAL (RADIUS).
- `GenieAcsClientService` (findDeviceById/queryDevices/sendTask/getStandardIdentity) — transport TR-069.
- `OltSidecarClient` (read/apply/saveConfig) — transport OMCI/CLI (sidecar).
- `OltDevice::sidecarVendorKey()` / `sidecarConnectionPayload()` — identitas & kredensial OLT.

---

## C. DESAIN DUA-LAPIS (usulan)

### Lapis 1 — ADAPTER (per merek modem + sub-mode PON)
**Kontrak (interface) konsisten** untuk operasi provisioning, diimplementasi per vendor:

```
interface CpeProvisioningAdapter {
  admitOnu(OltDevice, onuIdentity): AdmissionResult      // E04ID: bind-onu+confirm; lain: no-op/auto
  pushWanInternet(ctx, WanInternetSpec): Result          // VLAN, wan_mode, NAT, binding role
  pushWanBridge(ctx, WanBridgeSpec): Result              // VLAN, binding role (guest)
  pushSsid(ctx, SsidSpec[]): Result                      // per role: name/security/pass/band (+AddObject)
  applyNaming(OltDevice, name): Result                   // OMCI/CLI naming "Nama - CID"
  saveConfig(OltDevice): Result                          // HSGQ: copy run start; ZTE/TR-069: no-op
  verify(ctx, expectation): VerifyResult                 // baca balik WAN/SSID/bridge
  capabilities(): Capabilities                            // apa yang didukung (utk gating UI)
}
```
- **CT-COM adapter: diimplementasi PENUH**, dengan sub-mode GPON/EPON (reuse `ctcVlanConfigObject` + `bind-onu` admisi untuk EPON). Inti alur = `G02idActivationService` yang di-ctcVlanObj-kan + `default-wan.js` CT-COM.
- **ZTE C300 adapter: diimplementasi** (OMCI/CLI — tcont/gemport/service-port/flow via sidecar) — sebagian sudah ada jalur sidecar; naming/save beda (auto-write).
- **Huawei / CMCC / ZTE-generic: STUB eksplisit "belum didukung"** (lempar `UnsupportedAdapterOperation`, UI tampil "belum didukung") — field TR-069-nya sudah terkatalog (A) tapi belum diverifikasi hardware. Pola gating = `SUPPORTED_VENDOR_OPERATIONS` (B4).
- **Resolusi adapter**: `vendor_key` (dari `OltDevice::sidecarVendorKey()`) + `pon_mode` (auto-detect device, mis. objek EPON/GPON di tree, atau dari `olt_models.supported_pon_type`) → adapter dari REGISTRY (C-skema).

### Lapis 2 — TEMPLATE (vendor-agnostic) — TEPAT 2 jenis + WAN mgmt otomatis
1. **Template "WAN Internet"**: `wan_mode` (pppoe/dhcp/static), `vlan`, `nat_enabled` (on/off), diikat ke **PERAN WiFi** (`primary_wifi` / `guest_wifi` / `none`) — **bukan nomor instance hardcode**. PPPoE cred TIDAK disimpan di template (per-pelanggan: `{cid}@ppp.bajastu.id`), template hanya menyimpan STRATEGI (`derive_from_cid` vs `static`).
2. **Template "WAN Bridge"**: `vlan`, diikat ke peran WiFi (`guest_wifi`).
3. **WAN TR-069 mgmt**: **BUKAN template** — tetap otomatis (CT-COM: OMCI-driven VID9; ZTE: `security-mgmt 999` + Option 43). Tidak pernah diprovision dari template.
- **SSID = sub-bagian template**, diikat ke PERAN yang sama: `ssid_name` (boleh placeholder `{cid}`/`{name}`), `security` (open / wpa2), `passphrase` (atau open), `band` (2.4/5/both). Adapter yang menerjemahkan **peran → nomor instance** per vendor (mis. CT-COM: primary=WLAN1+5, guest=WLAN4+8; AddObject ditangani di dalam adapter).

**Pemisahan tanggung jawab (inti ZTP)**:
- **APA** diprovision = TEMPLATE (di-resolve dari paket pelanggan) — vendor-agnostic.
- **BAGAIMANA** di-push ke hardware = ADAPTER (dipilih dari vendor+pon_mode CPE).
- **SIAPA** (identitas) = Customer (cid → username/SSID/naming).

---

## D. USULAN SKEMA DATABASE

> Semua tabel tenant-scoped (`tenant_id`) kecuali adapter registry (platform-level, seperti
> `payment_gateway_channels`). Nama final bisa disesuaikan saat implementasi.

### D1. Adapter registry — `cpe_adapters` (platform-level, seeded, bukan di-edit user)
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | pk | |
| vendor_key | string | cocok `OltDevice::sidecarVendorKey()` / data-model TR-069 (ctcom/huawei/cmcc/zte_generic/zte_c300) |
| pon_mode | enum nullable | `gpon`/`epon`/null (null = tak relevan, mis. deteksi runtime) |
| handler_class | string | FQCN implementasi `CpeProvisioningAdapter` |
| transport | enum | `tr069` / `omci_cli` (GenieACS vs sidecar) |
| is_supported | bool | false = stub "belum didukung" (gating UI) |
| capabilities | json | `{admit,wan_internet,wan_bridge,ssid,naming,save}` bool masing-masing |
| notes | text nullable | |

Unik: `(vendor_key, pon_mode)`. Resolver: `vendor_key`+`pon_mode` device → row (fallback `pon_mode=null`).
*(Alternatif: registry murni di kode (array const seperti `SUPPORTED_VENDOR_OPERATIONS`) + tabel ini
hanya cache/visibilitas. Usulan: tabel, agar UI bisa tampilkan matriks dukungan.)*

### D2. Profil provisioning — `provisioning_profiles` (bundle template bernama)
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | pk | |
| tenant_id | fk | |
| name | string | mis. "Home PPPoE + Token WiFi" |
| slug | string | |
| description | text nullable | |
| enabled | bool | |
| timestamps | | |

Satu profil = satu WAN-Internet (wajib) + 0..1 WAN-Bridge + 0..N SSID.

### D3. `provisioning_wan_internets` (template "WAN Internet", 1:1 ke profil)
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | pk | |
| provisioning_profile_id | fk unique | one-to-one |
| wan_mode | enum | `pppoe`/`dhcp`/`static` |
| vlan | smallint | |
| nat_enabled | bool | |
| wifi_role | enum | `primary_wifi`/`guest_wifi`/`none` (peran WiFi yang di-bind, BUKAN instance) |
| pppoe_username_strategy | enum nullable | `derive_from_cid`/`static` (hanya wan_mode=pppoe) |
| pppoe_username_static | string nullable | |
| pppoe_password_strategy | enum nullable | `constant`/`static` |
| pppoe_password_static | text nullable (encrypted) | |
| static_ip / static_gw / static_mask / static_dns | nullable | hanya wan_mode=static |

### D4. `provisioning_wan_bridges` (template "WAN Bridge", 0..1 ke profil)
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | pk | |
| provisioning_profile_id | fk unique | nullable=tidak ada bridge |
| vlan | smallint | |
| wifi_role | enum | `guest_wifi`/`none` |

### D5. `provisioning_ssids` (SSID per profil, 0..N, diikat peran)
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | pk | |
| provisioning_profile_id | fk | |
| wifi_role | enum | `primary_wifi`/`guest_wifi` (dicocokkan dengan wifi_role WAN) |
| ssid_name_template | string | boleh `{cid}`/`{name}` placeholder |
| security | enum | `open`/`wpa2` |
| passphrase_strategy | enum nullable | `static`/`derive_from_cid`/none (open) |
| passphrase_static | text nullable (encrypted) | |
| band | enum | `b24`/`b5`/`both` |

Unik: `(provisioning_profile_id, wifi_role, band)`.

### D6. Keterhubungan Customer/OLT/CPE → profil (resolusi on-demand, TIDAK disimpan di device)
Pola DIPERTAHANKAN dari `WanConfigTemplateResolverService` (B6): device cuma tahu paket + tipe modem,
profil di-resolve saat aktivasi.
- **`ppp_packages.provisioning_profile_id`** (fk nullable) ATAU tabel map `package_provisioning_profiles
  (ppp_package_id unique, provisioning_profile_id)` — profil default per PAKET.
- **`cpe_devices.provisioning_profile_id`** (fk nullable) — OVERRIDE per-CPE opsional (menang atas paket).
- **Adapter** dipilih terpisah: `cpe_devices.modem_type_id` → `modem_types` (sudah ada) → `vendor_key` +
  `pon_mode` → `cpe_adapters` (D1). ONU/OLT: `OltDevice` memberi `vendor_key` & kredensial; `pon_mode`
  bisa dari `olt_models.supported_pon_type` atau auto-detect device.

**Resolver aktivasi (pseudo)**:
```
profile  = cpe.provisioning_profile_id ?? cpe.customer.ppp_package.provisioning_profile_id   // APA
adapter  = cpe_adapters[ vendor_key(cpe/olt), pon_mode(device) ]                             // BAGAIMANA
identity = { cid, name } dari customer                                                        // SIAPA
→ adapter.admit() → pushWanInternet(profile.wan_internet, identity)
  → pushWanBridge(profile.wan_bridge?) → pushSsid(profile.ssids, identity)
  → applyNaming("{name} - {cid}") → saveConfig()
```

### D7. Hubungan ke tabel lama (rencana pensiun, BUKAN dieksekusi sekarang)
- `remote_wan_configs` (singleton) → **digantikan** `provisioning_wan_internets`/`_bridges`. Pensiun
  setelah ZTP jalan; `default-wan.js` di-drive dari profil, bukan singleton args posisional.
- `wan_config_templates` + `WanConfigTemplateResolverService` → **digantikan/dimigrasikan** ke
  `provisioning_profiles` (superset: + SSID + role + wan_mode + adapter-aware). Data existing (jika ada)
  bisa dimigrasikan 1:1.
- `test_onu_wan_configs` / `test_onu_attached_vlans` → jalur test disatukan ke profil ZTP; guard
  `is_test_fixture`/`TestCredentialSyncService` tetap. Pensiun bertahap.

---

## E. PERTANYAAN TERBUKA untuk Agung (perlu keputusan sebelum implementasi)
1. **Adapter registry**: tabel DB (D1) atau const di kode + tabel visibilitas saja? (Usul: tabel.)
2. **Profil → paket**: kolom `ppp_packages.provisioning_profile_id` vs tabel map terpisah? Dan apakah
   override per-CPE (`cpe_devices.provisioning_profile_id`) diperlukan sekarang atau nanti?
3. **`wan_config_templates` lama**: migrasikan datanya ke profil, atau mulai bersih (tabel lama ditinggal
   sampai pensiun)? Ada data produksi di dalamnya?
4. **wan_mode selain PPPoE**: DHCP/Static benar-benar dibutuhkan di template Internet sekarang, atau
   PPPoE-only dulu (kolom disiapkan tapi UI PPPoE saja)?
5. **PON mode source**: auto-detect dari tree device (objek EPON/GPON) ATAU dari `olt_models.
   supported_pon_type`? (Auto-detect lebih akurat per-device; `olt_models` lebih sederhana.)
6. **Rename `G02idActivationService` → `CtcomActivationService`** (+ ctcVlanObj-kan `resolveInternetPppPathByVlan`/
   `verifyBridgePresent`) saat jadi adapter — setuju?

---

**Tidak ada migration/kode dibuat di dokumen ini.** Menunggu review & keputusan Agung atas skema + 6
pertanyaan terbuka sebelum implementasi sub-versi berikutnya.
