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
- **Resolusi adapter (keputusan #5)**: `vendor_key` (dari `OltDevice::sidecarVendorKey()`) + `pon_mode`
  (**derive dari `olt_devices.vendor` via `OltDevice::ponMode()`** — `hsgq_g02id`→`gpon`, `hsgq_e04id`→`epon`,
  `zte_c300`→`gpon`; TANPA probe tree / tabel baru) → adapter dari `AdapterRegistry` (kode, D1).

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

### D1. Adapter registry — KODE (sumber kebenaran) + `cpe_adapters` (tabel mirror status/visibilitas)
**Keputusan #1**: handler ditentukan KODE, bukan tabel. Di kode:
- `CpeProvisioningAdapter` (interface, lihat C) + implementasi per vendor.
- `AdapterRegistry` PHP: map `vendor_key(+pon_mode) → FQCN handler` (pola `OnuRegistryService::
  SUPPORTED_VENDOR_OPERATIONS`), + `UnsupportedAdapterOperation` untuk stub.
- `OltDevice::ponMode()`: derive `gpon`/`epon` dari `olt_devices.vendor` (keputusan #5) —
  `hsgq_g02id`→`gpon`, `hsgq_e04id`→`epon`, `zte_c300`→`gpon`. Tidak ada tabel/probe baru.

Tabel `cpe_adapters` HANYA mirror untuk matriks dukungan di UI (bukan penentu class):
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | pk | |
| vendor_key | string | `ctcom`/`huawei`/`cmcc`/`zte_generic`/`zte_c300` |
| pon_mode | enum nullable | `gpon`/`epon`/null |
| transport | enum | `tr069` / `omci_cli` |
| is_supported | bool | false = stub "belum didukung" |
| capabilities | json | `{admit,wan_internet,wan_bridge,ssid,naming,save}` + per-wan_mode flag `untested_on_hardware` (lihat D3/#4) |
| notes | text nullable | |

Unik: `(vendor_key, pon_mode)`. Baris di-seed dari registry kode (kode = sumber; seeder menulis mirror).

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
| wan_mode | enum | `pppoe`/`dhcp`/`static` (keputusan #4: ketiganya dibangun; **`dhcp`/`static` = `untested_on_hardware` — logic tulis belum diverifikasi device, UI wajib tampilkan peringatan, jangan diklaim production-ready; hanya `pppoe` production-ready**) |
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
Pola DIPERTAHANKAN dari `WanConfigTemplateResolverService` (B6): device cuma tahu paket + override-nya,
profil di-resolve saat aktivasi (bukan disimpan permanen di device).

**Keputusan #2 — MANY-TO-MANY via pivot** (bukan kolom di `ppp_packages`):
`package_provisioning_profiles`:
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | pk | |
| ppp_package_id | fk | |
| provisioning_profile_id | fk | |
| is_default | bool | penanda profil default paket ini (lihat aturan pemilihan) |

Unik: `(ppp_package_id, provisioning_profile_id)`. Satu paket boleh >1 profil & satu profil boleh dipakai
banyak paket. **`cpe_devices.provisioning_profile_id`** (fk nullable) = OVERRIDE per-CPE (menang di atas
paket).

**Aturan pemilihan resolver** (karena many-to-many bisa >1 kandidat):
1. `cpe.provisioning_profile_id` (override eksplisit) — menang.
2. else profil paket yang `is_default=true`.
3. else kalau paket punya tepat 1 profil → itu.
4. else (>1 non-default, tak ada default) → tidak auto-resolve; UI aktivasi WAJIB minta pilih eksplisit
   (hindari tebak-tebakan).

**Adapter dipilih terpisah (keputusan #5)**: dari **`olt_devices.vendor`** → `OltDevice::ponMode()`
(vendor→gpon/epon) + `vendor_key` → `AdapterRegistry` (kode). TIDAK pakai `olt_models.supported_pon_type`
maupun probe tree device. (`cpe_devices.modem_type_id` tetap dipakai untuk aturan SSID4/8 applicability
seperti sebelumnya, bukan untuk pilih adapter.)

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

## E. KEPUTUSAN FINAL TERKUNCI (Agung, v0.23.8)

1. **Adapter registry = KODE (class PHP) + tabel DB status/visibilitas.** Sumber kebenaran handler ada di
   kode (interface `CpeProvisioningAdapter` + implementasi + sebuah `AdapterRegistry` PHP yang memetakan
   `vendor_key(+pon_mode) → class`, pola sama `OnuRegistryService::SUPPORTED_VENDOR_OPERATIONS`). Tabel DB
   `cpe_adapters` HANYA mirror status/visibilitas (untuk matriks dukungan di UI + flag `is_supported`),
   **bukan** yang menentukan class mana dijalankan. Konsekuensi: adapter baru = tambah class + daftarkan
   di registry kode + 1 baris seed tabel; tabel tidak pernah jadi satu-satunya sumber.
2. **Paket ↔ Profil = tabel map terpisah, MANY-TO-MANY.** Pivot `package_provisioning_profiles`
   (`ppp_package_id`, `provisioning_profile_id`) — satu paket boleh punya >1 profil (mis. profil beda per
   skenario) dan satu profil boleh dipakai banyak paket. **TIDAK** ada kolom `provisioning_profile_id` di
   `ppp_packages`. Override per-CPE (`cpe_devices.provisioning_profile_id`, nullable) tetap disediakan
   sebagai pemenang resolusi. Karena many-to-many bisa menghasilkan >1 kandidat profil per paket, resolver
   butuh aturan pemilihan (lihat D6 — default-flag / pilihan eksplisit saat aktivasi).
3. **`wan_config_templates` lama = TIDAK ADA data produksi (0 baris, terkonfirmasi).** Mulai BERSIH —
   **tidak ada migrasi data**. Tabel/resolver lama ditinggalkan di tempat (dipensiunkan bertahap), ZTP
   pakai `provisioning_profiles` dari nol.
4. **WAN mode = bangun PPPoE + DHCP + Static sekarang (skema + UI).** TAPI: **logic TULIS DHCP/Static ke
   device BELUM PERNAH diverifikasi hardware** sepanjang sprint ini (hanya PPPoE yang teruji nyata —
   Dahlia GPON, unit-uji E04ID). → wajib flag eksplisit **`untested_on_hardware`** di level kode (konstanta/
   atribut capability per adapter per wan_mode) DAN peringatan di UI. **JANGAN diklaim production-ready**
   untuk DHCP/Static sampai diuji nyata. PPPoE = production-ready.
5. **PON mode = DERIVE dari `olt_devices.vendor` yang SUDAH ADA** (`hsgq_g02id`→`gpon`, `hsgq_e04id`→`epon`;
   `zte_c300`→`gpon`). **TIDAK** ada tabel baru, **TIDAK** ada live-probe device. Pemetaan vendor→pon_mode
   ada di kode (helper, mis. `OltDevice::ponMode()` atau di `AdapterRegistry`). *(Catatan: ini menyederhanakan
   dari usul auto-detect tree device di draf awal — vendor OLT sudah cukup menentukan GPON vs EPON.)*
6. **Rename `G02idActivationService` → `CtcomActivationService`** + perbaiki 2 method yang masih hardcode
   GPON (`resolveInternetPppPathByVlan`, `verifyBridgePresent`) agar pakai deteksi objek VLAN yang sama
   seperti `ctcVlanConfigObject()` (GPON/EPON) → otomatis berlaku untuk E04ID. Semua pemanggil lama
   di-update; test di-rename/di-extend untuk cakupan EPON.

---

## F. USULAN PEMECAHAN TAHAPAN IMPLEMENTASI (sub-versi kecil — usul Claude Code, bukan final)

Mengikuti kebiasaan sprint ini (sub-versi kecil, satu area per commit, verifikasi bertahap — BUKAN satu
commit raksasa). Tiap tahap: migration (kalau ada) + kode + test + `db:seed` ulang bila ada permission +
Pint, hijau sebelum lanjut. Verifikasi hardware HANYA di tahap yang menyentuh device nyata (pakai unit-uji
`CMDCA21C01E7` / NAS `ro-hotspot`, JANGAN `test-x86-bajastu` produksi).

- **v0.23.8.1 — Pondasi adapter (kode, nol device).** Interface `CpeProvisioningAdapter`, `AdapterRegistry`
  (map `vendor_key+pon_mode → class`, pola `SUPPORTED_VENDOR_OPERATIONS`), helper `OltDevice::ponMode()`
  (derive dari vendor, poin #5), migration + model `cpe_adapters` (mirror status/visibilitas) + seeder.
  Stub adapter Huawei/CMCC/ZTE-generic (`is_supported=false`, lempar `UnsupportedAdapterOperation`).
  Belum ada operasi tulis device. Test: resolusi registry, derive pon_mode, gating stub.
- **v0.23.8.2 — Rename + ctcVlanObj-kan `CtcomActivationService` (poin #6).** Rename
  `G02idActivationService`→`CtcomActivationService`, perbaiki `resolveInternetPppPathByVlan`/
  `verifyBridgePresent` pakai deteksi GPON/EPON, update pemanggil + test (tambah kasus EPON). Belum ada
  skema template — murni refactor agar satu service CT-COM melayani G02ID DAN E04ID. **Verifikasi:** re-run
  aktivasi path ke unit-uji (read-only/idempoten) memastikan tak ada regresi.
- **v0.23.8.3 — Skema template inti.** Migration + model: `provisioning_profiles`,
  `provisioning_wan_internets` (1:1, + flag `untested_on_hardware` untuk dhcp/static), `provisioning_wan_bridges`
  (0..1), pivot `package_provisioning_profiles`, `cpe_devices.provisioning_profile_id` (override). Enum
  `WanMode`(pppoe/dhcp/static), `WifiRole`(primary_wifi/guest_wifi/none). CRUD service + Form Request +
  policy/permission (`provisioning.view`/`.manage`). Resolver profil on-demand (pivot + override, aturan
  pemilihan D6). BELUM nyentuh device. Test penuh resolusi.
- **v0.23.8.4 — SSID template.** `provisioning_ssids` (per peran, placeholder `{cid}`/`{name}`, open/wpa2,
  band). Logika peran→instance per vendor DI DALAM adapter CT-COM (reuse AddObject sekuensial + resep open
  dari default-wan.js). Test render nama + resolusi peran→SSID spec.
- **v0.23.8.5 — Eksekusi ZTP end-to-end via adapter CT-COM (device nyata).** Orkestrator aktivasi yang
  meresolusi profil+adapter+identitas lalu memanggil adapter (admit→wan internet→bridge→ssid→naming→save).
  **Verifikasi hardware** ke unit-uji `CMDCA21C01E7` (E04ID) + sekali ke G02ID: hasilkan end-state yang sama
  seperti v0.23.6/v0.23.7 TAPI dari template, bukan hardcode. PPPoE-only yang diklaim production-ready.
- **v0.23.8.6 — UI "Profil Provisioning".** Halaman kelola profil + WAN Internet/Bridge + SSID + map paket,
  override per-CPE di Detail Perangkat. Peringatan `untested_on_hardware` tampil untuk DHCP/Static. Matriks
  dukungan adapter (dari `cpe_adapters`).
- **v0.23.8.7 — Pensiun tabel lama + `default-wan.js` di-drive profil.** `RemoteWanConfig` singleton &
  `wan_config_templates` ditandai deprecated / preset `default-wan` menerima args dari profil (bukan
  singleton). (Bisa digeser lebih akhir kalau risiko tinggi.)
- **(DILUAR scope ZTP, dicatat)**: adapter ZTE C300 penuh (OMCI tcont/gemport/service-port) + adapter
  Huawei/CMCC/ZTE-generic nyata — tahap tersendiri setelah pondasi CT-COM mapan, masing-masing butuh
  verifikasi hardware sendiri.

**Catatan urutan**: 8.1→8.2 (pondasi+refactor, nol risiko device) → 8.3→8.4 (skema, nol device) →
8.5 (device nyata, titik verifikasi utama) → 8.6 (UI) → 8.7 (pensiun). Tiap sub-versi di-merge `--no-ff`
develop→main + tag sesuai BOSS-013, FULL regression OLT-related hanya di titik yang menyentuh device/penutup
cluster (bukan tiap sub-versi), sesuai aturan regression sprint.

---

**Tidak ada migration/kode dibuat di dokumen ini.** Desain final (6 keputusan terkunci) + usulan tahapan
menunggu review Agung SEBELUM migration pertama (v0.23.8.1) ditulis.
