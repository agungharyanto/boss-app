# ONU Test UI — Model "1 WAN Aktif + Attached VLANs" (v0.23.5, DRAFT DESAIN)

> **Status: DRAFT untuk review Agung. BELUM diimplementasikan. Tidak ada kode/migration/eksekusi OLT
> yang ditulis untuk desain ini — murni investigasi + rancangan.** Semua yang ditandai `[ASUMSI]` /
> `[OPEN]` WAJIB divalidasi/dikonfirmasi sebelum implementasi.

## 0. Pivot desain — apa yang ditinggalkan

Model lama v0.23.5 ("3 WAN OMCI terpisah": WAN1 PPPoE + WAN2 TR069 + WAN3 bridge sebagai node `wan 1/2/3`
masing-masing) **DITINGGALKAN**. Alasan konkret dari eksplorasi CLI nyata sesi ini:

- `wan <n> service other` (kandidat WAN3/bridge) **WAJIB** diikuti `mvlan <id>`, dan `mvlan` terkonfirmasi
  via bantuan CLI = **"Configure UNI multicast VLAN tag operation"** (IPTV/multicast), **bukan** bridge/
  hotspot passthrough. Jalur `service other + mvlan` = salah jalur untuk bridge.
- Mekanisme probe `?` ternyata **tidak sepenuhnya read-only**: `wan 2 service internet ?` secara tak
  sengaja meng-commit record `wan 2 service internet` (pexpect mengirim `\r` setelah `?`, dan karena
  `wan 2 service internet` adalah command lengkap, ter-eksekusi). Record tak diminta itu masih ada di
  ONU_1, menunggu keputusan pembersihan terpisah.
- Keterbatasan nyata hardware/SmartOLT: **hanya SATU WAN aktif pada satu waktu** — multi-WAN simultan
  tidak dikejar lagi.

Model baru meniru SmartOLT: **SATU WAN aktif** + **daftar "Attached VLANs"** (izin lewat VLAN di level
`flow`/`vlan-filter` ONU, BUKAN WAN kedua).

## 1. Investigasi skema (SUDAH dilakukan, read-only) — sumber dropdown dinamis

**Rantai paket → VLAN** (tidak boleh hardcode): `PppPackage` → `network_profile_group_id` →
`NetworkProfileGroup.interface_name` → ekstrak nomor VLAN via regex `^vlan(\d+)-`. Ini satu-satunya tempat
VLAN tersimpan (NetworkProfileGroup tidak punya kolom VLAN eksplisit). Dikonfirmasi langsung dari DB
(`nas_id=3`, NAS uji `ro-hotspot.bajastu.id`):

| PppPackage (aktif) | group | interface_name | VLAN | CustomerIpPool (`routerOsPoolName`) |
|---|---|---|---|---|
| HomeFixed-10Mbps (#18) | 29 | vlan111-PPPoE-10Mbps-Loyalis | 111 | HomeFixed-10Mbps (pool) |
| HomeFixed-30Mbps (#19) | 30 | vlan131-PPPoE-30Mbps-Loyalis | 131 | HomeFixed-30Mbps |
| HomeFixed-50Mbps (#20) | 31 | vlan151-PPPoE-50Mbps-Loyalis | 151 | HomeFixed-50Mbps |
| HomeFixed-100Mbps (#21) | 32 | vlan101-PPPoE-100Mbps-Loyalis | 101 | HomeFixed-100Mbps |
| PPPoE-Remote (#14/#16/#17) | 24/27/28 | vlan10-PPPoE | 10 | PPPOE-REMOTE (group 28) |
| test-10Mbps-HomeFixed-1 (#2), HomeFixed-10Mbps (#6) | 11 | *(null)* | *(tak ada)* | - |

**[OPEN-1]** Beberapa grup (mis. #11) `interface_name` null → VLAN tak terderivasi. Dropdown harus
**menyembunyikan/menonaktifkan** paket yang grup-nya tak punya VLAN (tidak bisa jadi WAN PPPoE OMCI tanpa
VLAN). Alternatif: tampilkan dengan label "(VLAN belum diset di Grup Profil)" dan disable. **Rekomendasi:
sembunyikan**, konsisten dengan "JANGAN tawarkan opsi yang belum terbukti".

**Sumber query dropdown**: `PppPackage::where('is_active',true)->with('networkProfileGroup')->get()`, map
ke `{id, name, vlan}` di mana `vlan` diekstrak dari `interface_name`; buang yang vlan null. Ini meliputi
SEMUA paket aktif di sistem (10/101/111/131/150/151 dst), bukan cuma 10/111.

**Catatan**: NetworkProfileGroup juga punya VLAN 150 (#33) dan 110 (#15) yang BELUM punya PppPackage —
dropdown berbasis PppPackage tidak akan menampilkannya. Itu benar (dropdown ini soal PAKET pelanggan,
bukan semua grup). Kalau nanti perlu "VLAN mentah" (untuk Attached VLANs multi-select), sumbernya BEDA —
lihat §4.

## 2. Model data — **OPSI B DIPILIH** (keputusan Agung, diimplementasikan)

Semua ini **TEST-ONLY** (`customers.is_test_fixture=true`). Metadata pemetaan FISIK ONU tetap di
`customers.test_onu_metadata` JSON (`{olt_device_id, pon_interface, onu_id, sn, onu_type, vlan_mgmt,
vlan_bridge, tcont_profile, traffic_profile, ...}`). Konfigurasi WAN yang bisa diubah admin pindah ke
TABEL baru (bukan JSON):

**`test_onu_wan_configs`** (migration `2026_10_01_090000`): `customer_id` (FK unik, 1:1 — satu WAN aktif
per customer), `package_id` (FK ppp_packages nullable → VLAN PPPoE diturunkan dari
`NetworkProfileGroup.interface_name`, di-snapshot ke `vlan_pppoe`), `onu_mode` (`routing` saja aktif,
`App\Enums\TestOnuMode`), `wan_mode` (`pppoe` aktif; `dhcp`/`static`/`webpage` tersimpan tapi
apply-nya "Segera hadir", `App\Enums\TestOnuWanMode`), `config_method` (`omci`/`tr069`,
`App\Enums\TestOnuConfigMethod`), `pppoe_username`, `pppoe_password` (**`encrypted` cast**, pola sama
`OltDevice.telnet_password`), timestamps.

**`test_onu_attached_vlans`** (pivot): `test_onu_wan_config_id` (FK), `network_profile_group_id` (FK
nullable — VLAN 9/mentah tak selalu punya grup), `vlan_id` (sumber kebenaran). Unik `(config_id, vlan_id)`.
**VLAN 9 (remote mgmt) SELALU disertakan default & tidak bisa dihapus — di-enforce di
`TestCredentialSyncService::MANDATORY_ATTACHED_VLAN` (service, bukan hanya UI).**

Model: `App\Models\TestOnuWanConfig` (casts enum + encrypted) + `App\Models\TestOnuAttachedVlan`;
relasi `Customer::testOnuWanConfig()` (HasOne).

**Enum (apa pun opsinya)**:
- `onu_mode`: **hanya `routing`** untuk v1. `bridging` **DISABLED/disembunyikan** dengan catatan "belum
  didukung" — bridge OMCI belum pernah terbukti bekerja (lihat §0).
- `wan_mode`: `dhcp | static | pppoe | setup_via_webpage`. **[ASUMSI]** `static` & `setup_via_webpage`
  belum pernah diuji via OMCI di codebase ini — kalau dipilih, v1 sebaiknya **tolak dengan pesan "belum
  didukung"** (sama posture bridging), hanya `pppoe` (terbukti) + mungkin `dhcp` yang aktif. Dikonfirmasi
  di review.
- `config_method`: `omci | tr069`.

## 3. Service (USULAN) — perluas `TestCredentialSyncService` yang sudah ada

- `applyWanConfig(Customer $c, array $wanConfig, array $attachedVlans)`:
  - `config_method=omci`: jalankan ulang template OMCI yang **sudah terbukti** (`updatePackage()` existing:
    delete+recreate ONU dengan VLAN PPPoE dari paket) — DITAMBAH baris flow permission untuk tiap VLAN di
    `attached_vlans` (format `flow 1 pri 0 vlan <V>`, pola yang sudah ada di config ONU_1: `flow 1 pri 0
    vlan 9/10/172`). TANPA node `wan <n>` terpisah.
  - `config_method=tr069`: panggil `applyTr069WanConfig()` (di bawah).
- `applyTr069WanConfig(Customer $c, array $wanConfig)`: reuse mekanisme **RemoteWanConfig/GenieACS preset
  yang SUDAH ADA** (pola Test-1/Test-2, lihat section "GenieACS Auto-WAN" di CLAUDE.md), BUKAN baris
  `pppoe` OMCI. **[OPEN-2]** perlu dicek ulang titik integrasi `RemoteWanConfig` saat implementasi (di luar
  scope investigasi ini).
- **Remote Management** (`tr069-mgmt 1` + `ip-host 2` + VLAN 9) **TETAP** diterapkan di SETIAP aktivasi
  OMCI (sudah terbukti di ONU_1), TERPISAH dari pilihan `config_method`. `config_method` hanya soal
  provisioning WAN itu sendiri, bukan kanal manajemen ACS.

**Hapus/nonaktifkan pivot lama**: `OPERATIONS_WRITE['add_wan_bridge']` + `_build_add_wan_bridge_commands()`
(sidecar) + `OltSidecarClient::addWanBridge()` — semua dari pivot "wan node terpisah" yang ditinggalkan.
Diganti: `attached_vlans` → baris `flow 1 pri 0 vlan <V>` tambahan di dalam template OMCI yang sudah ada.
**(Catatan disiplin: penghapusan ini = coding, DITUNDA sampai desain disetujui — tidak dilakukan sesi
ini.)**

## 4. UI (USULAN) — 2 section di halaman customer detail, HANYA `is_test_fixture=true`

**a. "Update ONU Mode"** (mirip modal SmartOLT): dropdown Paket/VLAN (dinamis, §1), radio ONU mode
(Routing saja), radio WAN mode, radio Config method (OMCI/TR069), input username/password (muncul kalau
WAN mode=PPPoE), tombol Terapkan → `applyWanConfig()`.

**b. "Attached VLANs"** (multi-select): sumber = daftar VLAN dari SEMUA NetworkProfileGroup ppp yang punya
`interface_name` (bukan cuma yang ada PppPackage-nya) → `{vlan, display_name}`. **VLAN 9 selalu tercentang
& terkunci** (wajib remote management). **[OPEN-3]** Tabel "Attached VLANs" existing di
`cpe-devices/show.blade.php` (baris 199) ternyata **read-only** dari `$wanConnections` (GenieACS) — BUKAN
konsep permission editable. Jadi section baru ini **tidak bisa sekadar memperluas** tabel itu; butuh
komponen/data sendiri. (Halaman CPE detail ≠ halaman customer detail; perlu diputuskan di mana section ini
diletakkan — rekomendasi: customer detail, dekat `is_test_fixture` lain.)

## 5. Field yang SENGAJA belum diimplementasikan (ditandai, bukan di-skip diam-diam)

- **"ONU mode: Bridging"** — `[BELUM DIDUKUNG]`, disembunyikan/disable. Bridge OMCI belum pernah berhasil.
- **"WAN remote access"** (dropdown SmartOLT, isi "Disabled/not set" dsb) — `[FUNGSI BELUM DIPAHAMI]`,
  tidak diimplementasikan, dicatat sebagai field belum diketahui.
- `wan_mode = static | setup_via_webpage` — `[ASUMSI belum teruji]`, kemungkinan ditolak "belum didukung"
  di v1 (konfirmasi di review).

## 6. Yang perlu keputusan Anda sebelum implementasi
1. Model data: **Opsi A (JSON, rekomendasi)** vs Opsi B (tabel baru)?
2. `wan_mode`: aktifkan hanya `pppoe`(+`dhcp`)? atau keempat-empatnya dengan guard "belum didukung"?
3. Letak section UI (customer detail — dikonfirmasi?).
4. `[OPEN-1]` paket tanpa VLAN: sembunyikan (rekomendasi) atau tampilkan-disable?
