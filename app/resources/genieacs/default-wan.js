// default-wan — Auto-WAN provisioning, CONFIGURABLE dari BOSS App.
//
// Adaptasi dari 2 script provision referensi rekan Agung
// (auto_setup_wan_pppoe + auto_setup_wan_bridge, tersimpan di repo root
// sebagai auto_setup_wan_*.txt) yang meng-HARDCODE `const targetVlan =
// 1000` / `targetVlanWan2 = 1200`. Di sini nilai itu datang dari `args`
// preset — BOSS App (App\Services\Network\GenieAcsPresetService) menulis
// ulang `configurations[].args` preset `default` dari baris singleton
// `remote_wan_configs` setiap admin menyimpan halaman "Konfig Remote".
//
// KONTRAK ARGS (posisional — App\Models\RemoteWanConfig::toProvisionArgs()):
//   args[0] enabled            (bool)   master switch
//   args[1] wan1Enabled        (bool)   provisioning WAN1 (internet PPPoE)
//   args[2] wan1Vlan           (int)
//   args[3] wan1Username       (string) default PPPoE username
//   args[4] wan1Password       (string) default PPPoE password
//   args[5] wan2Enabled        (bool)   provisioning WAN2 (bridge kedua)
//   args[6] wan2Vlan           (int)
//   args[7] wan2SerialAllowlist (string CSV) — kosong = izinkan semua;
//           diisi = HANYA SN itu yang boleh diprovision WAN2 (fase testing)
//
// GenieACS meng-evaluasi tiap arg sebagai ekspresi; `args` di dalam script
// adalah array nilai hasil evaluasi. Kalau preset TIDAK memasukkan
// provision ini (master switch off di BOSS App), script ini tidak pernah
// dijalankan sama sekali — guard `enabled` di bawah hanya jaring pengaman
// kedua.
//
// PROVISION TERPISAH dari "default"/"default-optical"/"default-pppoe" —
// alasan isolasi sama: satu-satunya provision di preset ini yang benar-
// benar MENULIS nilai ke perangkat (declare 3-argumen `declare(path,
// null, {value})`), bukan sekadar refresh baca. Sebuah fault di sini
// tidak boleh menghentikan refresh SSID/Hosts/optik yang andal di
// provision lain.
//
// DETEKSI DATA MODEL — 4 cabang, urutan penting:
//   Huawei      : X_HW_SerialNumber ADA / manufacturer "Huawei ..."
//   CMCC        : X_CMCC_UserInfo.ServiceName ADA
//   ZTE generic : X_ZTE-COM_WANPONInterfaceConfig.RXPower ADA
//   CT-COM      : X_CT-COM_UserInfo.UserName ADA (fallback TERAKHIR) —
//     data model DOMINAN fleet ini (~372/415: ZTE F663NV3a/M63X,
//     Fiberhome GM220-S, CMDC H3-2S). VLAN per-WAN di LEVEL-WCD
//     (X_CT-COM_WANGponLinkConfig.VLANIDMark), tiap WAN di WCD terpisah
//     (bukan instance ke-2 di WCD.1). Diverifikasi 2026-09-07 dari
//     template CMDCA21C01E7 (di-konfig manual: WAN1 PPPoE VID 111 +
//     WAN2 bridge VID 172 + multi-SSID). GUARD idempoten CT-COM SUDAH
//     dites ke device asli (skip semua). PROVISIONING WAN BARU CT-COM ke
//     device FRESH = **KNOWN BUG** (instance number hardcoded `.1`,
//     device bikin di `.2` → `preset_loop`). VERIFIED BROKEN pada
//     CMDCA473F158 2026-09-08 — lihat blok "KNOWN BUG" tepat sebelum
//     definisi ctcFreeWcd(). Fitur DISABLED by default, AMAN untuk fleet.
//
// IDEMPOTEN — cek BERDASARKAN ISI, bukan posisi:
//   WAN1: skip kalau SUDAH ada WANPPPConnection.*.1.Username terisi di
//     perangkat (WCD 1-8 di-sapu — pelanggan existing tidak disentuh,
//     hanya ONT baru/factory-reset).
//   WAN2: skip kalau SUDAH ada koneksi ber-ConnectionType "*Bridged*"
//     dengan VLAN == wan2Vlan di POSISI MANA PUN (WCD 1-8; VLAN dicek di
//     field yang benar per data model — CT-COM di level-WCD) + gate SN
//     allowlist. Kalau lolos guard, bridge BARU dibuat (H/C/Z:
//     instance .2 di WCD.1; CT-COM: WCD kosong berikutnya). Binding SSID
//     di-assert ulang tiap Inform hanya kalau device genuinely dapat WAN2.

const enabled = args[0] === true || args[0] === "true" || args[0] === 1;
if (enabled) {
  const wan1Enabled = args[1] === true || args[1] === "true" || args[1] === 1;
  const wan1Vlan = parseInt(args[2], 10);
  const wan1Username = String(args[3] != null ? args[3] : "default");
  const wan1Password = String(args[4] != null ? args[4] : "default");
  const wan2Enabled = args[5] === true || args[5] === "true" || args[5] === 1;
  const wan2Vlan = parseInt(args[6], 10);
  // SN allowlist WAN2 (CSV) — lapisan keamanan tambahan fase testing.
  // Kosong = izinkan semua. Diisi = HANYA SN itu yang boleh diprovision
  // WAN2 (dilonggarkan setelah guard content-based di bawah terbukti).
  const wan2Allowlist = String(args[7] != null ? args[7] : "")
    .split(",").map(function (s) { return s.trim(); }).filter(function (s) { return s.length > 0; });

  const manufacturer = declare("DeviceID.Manufacturer", { value: 1 }).value[0];
  const hwSerial = declare("InternetGatewayDevice.DeviceInfo.X_HW_SerialNumber", { value: 1 });
  const isHuawei = (hwSerial.size && hwSerial.value[0]) || manufacturer === "Huawei Technologies Co., Ltd";

  // Deteksi data model — sama urutan persis dengan script referensi:
  // CMCC dulu (via X_CMCC_UserInfo.ServiceName), baru fallback ZTE generic
  // (via X_ZTE-COM_WANPONInterfaceConfig.RXPower). JANGAN digeneralisasi —
  // path parameter beda per vendor.
  const cmccCheck = declare("InternetGatewayDevice.X_CMCC_UserInfo.ServiceName", { value: 1 });
  const isCMCC = cmccCheck.size && cmccCheck.value[0] !== undefined;
  const zteRx = declare("InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.RXPower", { value: 1 });
  const isZTEGeneric = !isCMCC && zteRx.size && zteRx.value[0] !== undefined;

  // CT-COM (China Telecom / CTC unified data model) — data model DOMINAN di
  // fleet ini (~372/415 device: ZTE F663NV3a/M63X, Fiberhome GM220-S, dan
  // CMDC H3-2S). Diverifikasi 2026-09-07 dari device template
  // CMDCA21C01E7 (di-konfig manual Agung: WAN1 PPPoE VID 111, WAN2 bridge
  // VID 172, multi-SSID). Deteksi = X_CT-COM_UserInfo.UserName ADA. Ini
  // fallback TERAKHIR — sebagian device Huawei juga expose X_CT-COM_*,
  // jadi cuma dipakai kalau 3 deteksi di atas semua false.
  const ctcUser = declare("InternetGatewayDevice.X_CT-COM_UserInfo.UserName", { value: 1 });
  const isCTCom = !isHuawei && !isCMCC && !isZTEGeneric && ctcUser.size && ctcUser.value[0] !== undefined;

  const wanDevicePath = "InternetGatewayDevice.WANDevice.1";

  // ══════════════════════════════════════════════════════════════════════
  // KNOWN BUG — cabang isCTCom PROVISIONING (WAN1 + WAN2): instance number
  // WANPPPConnection di-HARDCODE `.1` (lihat `const basePath = ...".1"`).
  // Device CT-COM nyata bisa membuat instance di nomor MANA PUN (mis. `.2`)
  // setelah `declare("${path}.*", null, { path: 1 })` — nomor instance
  // TR-069 di objek dinamis TIDAK stabil/berurutan (persis gotcha yang
  // sudah dicatat CLAUDE.md untuk Hosts.Host.{n}, v0.7.6).
  //
  // Akibatnya:
  //  - Username / X_CT-COM_ServiceList / X_CT-COM_LanInterface ditulis ke
  //    `.1` yang tidak ada → tidak pernah ter-set.
  //  - `ctcFreeWcd()` di bawah cuma memindai instance `.1` → WCD yang sudah
  //    kepakai (di instance `.2`) tetap terlihat "kosong" → cabang WAN1 &
  //    WAN2 sama-sama merebut WCD yang sama, VLANIDMark ke-timpa
  //    (wan1Vlan → wan2Vlan).
  //  - Script re-run tiap Inform, tidak pernah konvergen → GenieACS raise
  //    fault `preset_loop`.
  //
  // VERIFIED BROKEN pada CMDCA473F158 (CMDC H3-2S XPON, fw V1.1.20P1T4),
  // 2026-09-08 — device fresh, provisioning menghasilkan 1 WANPPPConnection
  // setengah jadi di WCD.1 instance 2 (Enable=false, Username kosong,
  // VLANIDMark=172) + 2 fault `preset_loop` (boss-auto-wan + default).
  //
  // FIX yang diperlukan (belum dikerjakan):
  //  1. Setelah `declare("${path}.*", null, { path: 1 })` + `commit()`,
  //     RE-READ nomor instance sebenarnya (`declare("${path}.*", { value: 1 })`
  //     lalu ambil key numerik pertama) — jangan asumsikan `.1`.
  //  2. `ctcFreeWcd()` harus memindai SEMUA instance per WCD (pakai
  //     `WANPPPConnectionNumberOfEntries` / `WANIPConnectionNumberOfEntries`
  //     + loop instance), bukan cuma `.1`.
  //  3. Retest ke device CT-COM yang sudah di-factory-reset.
  //
  // MITIGASI SEKARANG: fitur ini DISABLED by default
  // (RemoteWanConfig.enabled = false → preset `boss-auto-wan` tidak dibuat,
  // NOL device fleet kena). SN allowlist in-script (args[7]) = jaring
  // pengaman tambahan selama fase testing. AMAN untuk fleet apa adanya.
  // ══════════════════════════════════════════════════════════════════════

  // CT-COM: VLAN tiap WAN ada di X_CT-COM_WANGponLinkConfig LEVEL-WCD
  // (`WANConnectionDevice.{N}.X_CT-COM_WANGponLinkConfig.VLANIDMark`),
  // BUKAN di connection. Helper cari slot WCD kosong pertama (1..8) untuk
  // provisioning WAN baru — model CT-COM menaruh tiap WAN di WCD terpisah
  // (device auto-assign slot, tidak konsisten: H3-2S pakai WCD.1 spare +
  // WCD.2 TR069; F663NV3a pakai WCD.1 TR069 tanpa spare). "Kosong" =
  // tidak ada ConnectionType di WANIPConnection.1 MAUPUN WANPPPConnection.1.
  // FUNGSI (bukan nilai) — dipanggil ulang di blok WAN1 & WAN2 supaya WAN2
  // dapat slot BERIKUTNYA setelah WAN1 tercatat; kalau read belum
  // ter-refresh dalam eksekusi yang sama, WAN2 skip Inform ini & konvergen
  // di Inform berikutnya (sama pola guard idempoten lain).
  function ctcFreeWcd() {
    if (!isCTCom) return 0;
    for (let wcd = 1; wcd <= 8; wcd++) {
      const ip = declare(`${wanDevicePath}.WANConnectionDevice.${wcd}.WANIPConnection.1.ConnectionType`, { value: Date.now() });
      const ppp = declare(`${wanDevicePath}.WANConnectionDevice.${wcd}.WANPPPConnection.1.ConnectionType`, { value: Date.now() });
      const ipSet = ip.size && ip.value && ip.value[0];
      const pppSet = ppp.size && ppp.value && ppp.value[0];
      if (!ipSet && !pppSet) return wcd;
    }
    return 0;
  }

  // ───────────────────────── WAN1: internet PPPoE ─────────────────────────
  // GUARD berbasis ISI, bukan posisi: skip TOTAL kalau device SUDAH punya
  // WANPPPConnection dengan Username terisi di POSISI MANA PUN. Script
  // referensi cuma cek WANConnectionDevice 1-5 instance .1 — tapi CLAUDE.md
  // sendiri mencatat koneksi INTERNET pelanggan nyata pernah ada di
  // WANConnectionDevice.6 (dan instance .2 untuk sebagian). Diperlebar ke
  // WCD 1-8 x WANPPPConnection 1-3 supaya pelanggan existing TIDAK PERNAH
  // ter-provision ulang tak sengaja.
  if (wan1Enabled && !isNaN(wan1Vlan)) {
    let wan1AlreadyConfigured = false;
    for (let wcd = 1; wcd <= 8 && !wan1AlreadyConfigured; wcd++) {
      for (let inst = 1; inst <= 2 && !wan1AlreadyConfigured; inst++) {
        const pppCheck = declare(`${wanDevicePath}.WANConnectionDevice.${wcd}.WANPPPConnection.${inst}.Username`, { value: Date.now() });
        if (pppCheck.size && pppCheck.value && pppCheck.value[0]) {
          wan1AlreadyConfigured = true;
        }
      }
    }

    if (!wan1AlreadyConfigured) {
      if (isHuawei) {
        const wanConnPath = `${wanDevicePath}.WANConnectionDevice.1.WANPPPConnection`;
        declare(`${wanConnPath}.*`, null, { path: 1 });
        commit();
        const basePath = `${wanConnPath}.1`;
        declare(`${basePath}.Username`, null, { value: wan1Username });
        declare(`${basePath}.Password`, null, { value: wan1Password });
        commit();
        declare(`${basePath}.Enable`, null, { value: true });
        declare(`${basePath}.ConnectionType`, null, { value: "IP_Routed" });
        declare(`${basePath}.X_HW_VLAN`, null, { value: wan1Vlan });
        commit();
        const nat = declare(`${basePath}.NATEnabled`, { value: Date.now() });
        if (!nat.size || nat.value[0] != true) {
          declare(`${basePath}.NATEnabled`, null, { value: true });
          commit();
        }
        const ssid1 = declare(`${basePath}.X_HW_LANBIND.SSID1Enable`, { value: Date.now() });
        if (!ssid1.size || ssid1.value[0] != 1) {
          declare(`${basePath}.X_HW_LANBIND.SSID1Enable`, null, { value: 1 });
          commit();
        }
        const lan1 = declare(`${basePath}.X_HW_LANBIND.Lan1Enable`, { value: Date.now() });
        if (!lan1.size || lan1.value[0] != 1) {
          declare(`${basePath}.X_HW_LANBIND.Lan1Enable`, null, { value: 1 });
          commit();
        }
      } else if (isCMCC) {
        const wan1PppPath = `${wanDevicePath}.WANConnectionDevice.1.WANPPPConnection`;
        declare(`${wan1PppPath}.*`, null, { path: 1 });
        commit();
        const basePath = `${wan1PppPath}.1`;
        declare(`${basePath}.Username`, null, { value: wan1Username });
        declare(`${basePath}.Password`, null, { value: wan1Password });
        commit();
        declare(`${basePath}.Enable`, null, { value: true });
        declare(`${basePath}.ConnectionType`, null, { value: "PPPoE_Routed" });
        declare(`${basePath}.X_CMCC_ServiceList`, null, { value: "INTERNET" });
        declare(`${basePath}.X_CMCC_VLANMode`, null, { value: 2 });
        declare(`${basePath}.X_CMCC_VLANIDMark`, null, { value: wan1Vlan });
        commit();
        const nat = declare(`${basePath}.NATEnabled`, { value: Date.now() });
        if (!nat.size || nat.value[0] != true) {
          declare(`${basePath}.NATEnabled`, null, { value: true });
          commit();
        }
        declare(`${basePath}.X_CMCC_LanInterface`, null, {
          value: "InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1,InternetGatewayDevice.LANDevice.1.WLANConfiguration.1",
        });
        commit();
      } else if (isZTEGeneric) {
        const wan1PppPath = `${wanDevicePath}.WANConnectionDevice.1.WANPPPConnection`;
        declare(`${wan1PppPath}.*`, null, { path: 1 });
        commit();
        const basePath = `${wan1PppPath}.1`;
        declare(`${basePath}.Username`, null, { value: wan1Username });
        declare(`${basePath}.Password`, null, { value: wan1Password });
        commit();
        declare(`${basePath}.Enable`, null, { value: true });
        declare(`${basePath}.ConnectionType`, null, { value: "PPPoE_Routed" });
        declare(`${basePath}.X_ZTE-COM_ServiceList`, null, { value: "INTERNET,TR069" });
        declare(`${basePath}.X_ZTE-COM_VLANEnable`, null, { value: true });
        declare(`${basePath}.X_ZTE-COM_VLANID`, null, { value: wan1Vlan });
        commit();
        const nat = declare(`${basePath}.NATEnabled`, { value: Date.now() });
        if (!nat.size || nat.value[0] != true) {
          declare(`${basePath}.NATEnabled`, null, { value: true });
          commit();
        }
        declare(`${basePath}.X_ZTE-COM_LanInterface`, null, {
          value: "InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1,InternetGatewayDevice.LANDevice.1.WLANConfiguration.1",
        });
        commit();
      } else if (isCTCom && ctcFreeWcd() > 0) {
        // CT-COM WAN1 = routed PPPoE. Diverifikasi dari template
        // CMDCA21C01E7 (`2_INTERNET_R_VID_111`):
        //  - WANPPPConnection.1 di WCD kosong (bukan instance .2 di WCD.1)
        //  - Username/Password = field STANDAR (bukan X_CT-COM_IPoE*)
        //  - ConnectionType = "IP_Routed" (BUKAN "PPPoE_Routed" — device
        //    tetap routed-PPPoE selama Username terisi)
        //  - VLAN di X_CT-COM_WANGponLinkConfig.VLANIDMark LEVEL-WCD + Mode=2
        //  - X_CT-COM_ServiceList = "INTERNET"
        //  - X_CT-COM_LanInterface = CSV path SSID (default SSID1+SSID5 =
        //    wifi rumah), X_CT-COM_LanInterface-DHCPEnable = true (LAN mode)
        // ⚠️ VERIFIED BROKEN ke device CT-COM fresh (CMDCA473F158,
        // 2026-09-08): `${wan1PppPath}.1` di bawah = instance hardcoded,
        // device bikin di `.2` → writes ke `.1` hilang → `preset_loop`.
        // Lihat blok "KNOWN BUG" sebelum ctcFreeWcd(). Guard idempoten
        // (skip device yang sudah dikonfig) sudah OK.
        const w1Wcd = ctcFreeWcd();
        const wcdPath = `${wanDevicePath}.WANConnectionDevice.${w1Wcd}`;
        const wan1PppPath = `${wcdPath}.WANPPPConnection`;
        declare(`${wan1PppPath}.*`, null, { path: 1 });
        commit();
        const basePath = `${wan1PppPath}.1`;
        declare(`${basePath}.Username`, null, { value: wan1Username });
        declare(`${basePath}.Password`, null, { value: wan1Password });
        commit();
        declare(`${basePath}.Enable`, null, { value: true });
        declare(`${basePath}.ConnectionType`, null, { value: "IP_Routed" });
        declare(`${basePath}.X_CT-COM_ServiceList`, null, { value: "INTERNET" });
        commit();
        declare(`${wcdPath}.X_CT-COM_WANGponLinkConfig.Enable`, null, { value: true });
        declare(`${wcdPath}.X_CT-COM_WANGponLinkConfig.Mode`, null, { value: 2 });
        declare(`${wcdPath}.X_CT-COM_WANGponLinkConfig.VLANIDMark`, null, { value: wan1Vlan });
        commit();
        const nat = declare(`${basePath}.NATEnabled`, { value: Date.now() });
        if (!nat.size || nat.value[0] != true) {
          declare(`${basePath}.NATEnabled`, null, { value: true });
          commit();
        }
        declare(`${basePath}.X_CT-COM_LanInterface`, null, {
          value: "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1,InternetGatewayDevice.LANDevice.1.WLANConfiguration.5",
        });
        declare(`${basePath}.X_CT-COM_LanInterface-DHCPEnable`, null, { value: true });
        commit();
      }
    }
  }

  // ───────────────────────── WAN2: bridge kedua ──────────────────────────
  // PENTING (dari script referensi): WAN kedua BARU dibuat sebagai INSTANCE
  // di WANConnectionDevice.1 yang SAMA (instance .2), BUKAN
  // WANConnectionDevice.2 — perangkat cuma punya 1 slot WANConnectionDevice.
  // Huawei pakai WANIPConnection.2 (IP_Bridged), CMCC/ZTE pakai
  // WANPPPConnection.2 (PPPoE_Bridged). Semua NAT OFF.
  //
  // GUARD v2 (redesign 2026-09-07, keputusan Agung) — cek BERDASARKAN ISI,
  // BUKAN posisi. "0 device punya WAN2" itu cuma soal slot instance ke-2 —
  // sebagian device mungkin sudah punya bridge WAN di slot LAIN (hasil
  // konfigurasi manual teknisi beda-beda). Guard yang cuma cek posisi bisa
  // gagal deteksi bridge existing lalu bikin DOBEL. `bridgeWithTargetVlanExists()`
  // menyapu WANConnectionDevice 1-2 × {WANIPConnection, WANPPPConnection}
  // 1-3 mencari SATU koneksi yang `ConnectionType` mengandung "bridg"
  // (case-insensitive: IP_Bridged / PPPoE_Bridged) DAN VLAN ID-nya
  // (X_HW_VLAN / X_CMCC_VLANIDMark / X_ZTE-COM_VLANID) == wan2Vlan, di posisi
  // MANA PUN. Kalau ada → skip total. Bias ke aman: kalau ragu (field tak
  // terbaca) diperlakukan sebagai "tidak ada", TAPI SN allowlist di bawah
  // adalah jaring pengaman sesungguhnya selama guard ini belum terverifikasi
  // ke device asli.
  if (wan2Enabled && !isNaN(wan2Vlan)) {
    const deviceSerial = String((declare("DeviceID.SerialNumber", { value: 1 }).value || [""])[0] || "");
    const serialAllowed = wan2Allowlist.length === 0 || wan2Allowlist.indexOf(deviceSerial) !== -1;

    // Field VLAN per data model. CT-COM: VLAN ada di
    // X_CT-COM_WANGponLinkConfig LEVEL-WCD, bukan di connection — jadi
    // dicek terpisah di dalam loop, `vlanFieldFor` di sini hanya untuk
    // H/C/Z (VLAN di level connection).
    const vlanFieldFor = isHuawei ? "X_HW_VLAN" : (isCMCC ? "X_CMCC_VLANIDMark" : "X_ZTE-COM_VLANID");
    let bridgeWithTargetVlanExists = false;
    // WCD scan diperlebar 1-2 → 1-8: model CT-COM menaruh bridge di WCD
    // terpisah (template CMDCA21C01E7: bridge di WCD.4; F663NV3a: WCD.4).
    for (let wcd = 1; wcd <= 8 && !bridgeWithTargetVlanExists; wcd++) {
      for (let ci = 0; ci < 2 && !bridgeWithTargetVlanExists; ci++) {
        const connType = ci === 0 ? "WANIPConnection" : "WANPPPConnection";
        for (let inst = 1; inst <= 3 && !bridgeWithTargetVlanExists; inst++) {
          const scanBase = `${wanDevicePath}.WANConnectionDevice.${wcd}.${connType}.${inst}`;
          const ct = declare(`${scanBase}.ConnectionType`, { value: Date.now() });
          if (!(ct.size && ct.value && ct.value[0])) continue;
          if (String(ct.value[0]).toLowerCase().indexOf("bridg") === -1) continue;
          if (isCTCom) {
            const ctcVlan = declare(`${wanDevicePath}.WANConnectionDevice.${wcd}.X_CT-COM_WANGponLinkConfig.VLANIDMark`, { value: Date.now() });
            if (ctcVlan.size && ctcVlan.value && parseInt(ctcVlan.value[0], 10) === wan2Vlan) {
              bridgeWithTargetVlanExists = true;
            }
            continue;
          }
          const scanVlan = declare(`${scanBase}.${vlanFieldFor}`, { value: Date.now() });
          if (scanVlan.size && scanVlan.value && parseInt(scanVlan.value[0], 10) === wan2Vlan) {
            bridgeWithTargetVlanExists = true;
          }
        }
      }
    }

    const shouldProvisionWan2 = serialAllowed && !bridgeWithTargetVlanExists;
    const wan2LanTarget = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.2";

    if (shouldProvisionWan2 && isHuawei) {
      const wan1IpPath = `${wanDevicePath}.WANConnectionDevice.1.WANIPConnection`;
      const wan2Check = declare(`${wan1IpPath}.2.ConnectionType`, { value: Date.now() });
      if (!(wan2Check.size && wan2Check.value[0])) {
        declare(`${wan1IpPath}.*`, null, { path: 2 });
        commit();
        const base2Path = `${wan1IpPath}.2`;
        declare(`${base2Path}.Enable`, null, { value: true });
        declare(`${base2Path}.ConnectionType`, null, { value: "IP_Bridged" });
        declare(`${base2Path}.X_HW_VLAN`, null, { value: wan2Vlan });
        commit();
        const nat2 = declare(`${base2Path}.NATEnabled`, { value: Date.now() });
        if (!nat2.size || nat2.value[0] != false) {
          declare(`${base2Path}.NATEnabled`, null, { value: false });
          commit();
        }
      }
      const ssid2 = declare(`${wan1IpPath}.2.X_HW_LANBIND.SSID2Enable`, { value: Date.now() });
      if (!ssid2.size || ssid2.value[0] != 1) {
        declare(`${wan1IpPath}.2.X_HW_LANBIND.SSID2Enable`, null, { value: 1 });
        commit();
      }
    } else if (shouldProvisionWan2 && isCMCC) {
      const wan1PppPath = `${wanDevicePath}.WANConnectionDevice.1.WANPPPConnection`;
      const wan2Check = declare(`${wan1PppPath}.2.ConnectionType`, { value: Date.now() });
      if (!(wan2Check.size && wan2Check.value[0])) {
        declare(`${wan1PppPath}.*`, null, { path: 2 });
        commit();
        const base2Path = `${wan1PppPath}.2`;
        declare(`${base2Path}.Enable`, null, { value: true });
        declare(`${base2Path}.ConnectionType`, null, { value: "PPPoE_Bridged" });
        declare(`${base2Path}.X_CMCC_ServiceList`, null, { value: "OTHER" });
        declare(`${base2Path}.X_CMCC_VLANMode`, null, { value: 2 });
        declare(`${base2Path}.X_CMCC_VLANIDMark`, null, { value: wan2Vlan });
        commit();
        const nat2 = declare(`${base2Path}.NATEnabled`, { value: Date.now() });
        if (!nat2.size || nat2.value[0] != false) {
          declare(`${base2Path}.NATEnabled`, null, { value: false });
          commit();
        }
      }
      const wan2Lan = declare(`${wan1PppPath}.2.X_CMCC_LanInterface`, { value: Date.now() });
      if (!wan2Lan.size || wan2Lan.value[0] !== wan2LanTarget) {
        declare(`${wan1PppPath}.2.X_CMCC_LanInterface`, null, { value: wan2LanTarget });
        commit();
      }
    } else if (shouldProvisionWan2 && isZTEGeneric) {
      const wan1PppPath = `${wanDevicePath}.WANConnectionDevice.1.WANPPPConnection`;
      const wan2Check = declare(`${wan1PppPath}.2.ConnectionType`, { value: Date.now() });
      if (!(wan2Check.size && wan2Check.value[0])) {
        declare(`${wan1PppPath}.*`, null, { path: 2 });
        commit();
        const base2Path = `${wan1PppPath}.2`;
        declare(`${base2Path}.Enable`, null, { value: true });
        declare(`${base2Path}.ConnectionType`, null, { value: "PPPoE_Bridged" });
        declare(`${base2Path}.X_ZTE-COM_ServiceList`, null, { value: "OTHER" });
        declare(`${base2Path}.X_ZTE-COM_VLANEnable`, null, { value: true });
        declare(`${base2Path}.X_ZTE-COM_VLANID`, null, { value: wan2Vlan });
        commit();
        const nat2 = declare(`${base2Path}.NATEnabled`, { value: Date.now() });
        if (!nat2.size || nat2.value[0] != false) {
          declare(`${base2Path}.NATEnabled`, null, { value: false });
          commit();
        }
      }
      const wan2Lan = declare(`${wan1PppPath}.2.X_ZTE-COM_LanInterface`, { value: Date.now() });
      if (!wan2Lan.size || wan2Lan.value[0] !== wan2LanTarget) {
        declare(`${wan1PppPath}.2.X_ZTE-COM_LanInterface`, null, { value: wan2LanTarget });
        commit();
      }
    } else if (shouldProvisionWan2 && isCTCom && ctcFreeWcd() > 0) {
      // CT-COM WAN2 = bridge. Diverifikasi dari template CMDCA21C01E7
      // (`3_INTERNET_B_VID_172`):
      //  - WANPPPConnection.1 di WCD kosong TERPISAH (ctcFreeWcd sudah
      //    memperhitungkan WAN1 yang mungkin baru dibuat di atas — helper
      //    memindai ulang tiap eksekusi, jadi WAN2 dapat slot berikutnya
      //    pada Inform setelah WAN1 tercatat)
      //  - ConnectionType = "PPPoE_Bridged", Username kosong, NAT OFF
      //  - VLAN di X_CT-COM_WANGponLinkConfig.VLANIDMark LEVEL-WCD + Mode=2
      //  - X_CT-COM_ServiceList = "INTERNET"
      //  - X_CT-COM_LanInterface = SSID4+SSID8 (default "TOKEN WIFI" /
      //    hotspot bridged), X_CT-COM_LanInterface-DHCPEnable = false (WAN mode)
      // ⚠️ VERIFIED BROKEN ke device CT-COM fresh (CMDCA473F158,
      // 2026-09-08): sama akar bugnya dengan WAN1 — `ctcFreeWcd()` cuma
      // scan instance `.1`, jadi WCD yang WAN1 sudah pakai (di `.2`) masih
      // terlihat kosong → WAN2 rebut WCD yang sama, VLANIDMark ke-timpa
      // (wan1Vlan → wan2Vlan). Lihat blok "KNOWN BUG" sebelum ctcFreeWcd().
      const w2Wcd = ctcFreeWcd();
      const wcdPath = `${wanDevicePath}.WANConnectionDevice.${w2Wcd}`;
      const wan2PppPath = `${wcdPath}.WANPPPConnection`;
      const wan2Check = declare(`${wan2PppPath}.1.ConnectionType`, { value: Date.now() });
      if (!(wan2Check.size && wan2Check.value[0])) {
        declare(`${wan2PppPath}.*`, null, { path: 1 });
        commit();
        const base2Path = `${wan2PppPath}.1`;
        declare(`${base2Path}.Enable`, null, { value: true });
        declare(`${base2Path}.ConnectionType`, null, { value: "PPPoE_Bridged" });
        declare(`${base2Path}.X_CT-COM_ServiceList`, null, { value: "INTERNET" });
        commit();
        declare(`${wcdPath}.X_CT-COM_WANGponLinkConfig.Enable`, null, { value: true });
        declare(`${wcdPath}.X_CT-COM_WANGponLinkConfig.Mode`, null, { value: 2 });
        declare(`${wcdPath}.X_CT-COM_WANGponLinkConfig.VLANIDMark`, null, { value: wan2Vlan });
        commit();
        const nat2 = declare(`${base2Path}.NATEnabled`, { value: Date.now() });
        if (!nat2.size || nat2.value[0] != false) {
          declare(`${base2Path}.NATEnabled`, null, { value: false });
          commit();
        }
        declare(`${base2Path}.X_CT-COM_LanInterface`, null, {
          value: "InternetGatewayDevice.LANDevice.1.WLANConfiguration.4,InternetGatewayDevice.LANDevice.1.WLANConfiguration.8",
        });
        declare(`${base2Path}.X_CT-COM_LanInterface-DHCPEnable`, null, { value: false });
        commit();
      }
    }
  }
}
