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
// IDEMPOTEN — cek BERDASARKAN ISI, bukan posisi:
//   WAN1: skip kalau SUDAH ada WANPPPConnection.*.1.Username terisi di
//     perangkat (pelanggan existing tidak disentuh, hanya ONT baru/
//     factory-reset).
//   WAN2: skip kalau SUDAH ada koneksi ber-ConnectionType "*Bridged*"
//     dengan VLAN == wan2Vlan di POSISI MANA PUN (bukan cuma slot .2) +
//     gate SN allowlist. Kalau lolos guard, bridge BARU dibuat di
//     WANConnectionDevice.1 instance .2; binding SSID2 di-assert ulang
//     tiap Inform hanya kalau device memang genuinely dapat WAN2.

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

  const wanDevicePath = "InternetGatewayDevice.WANDevice.1";

  // ───────────────────────── WAN1: internet PPPoE ─────────────────────────
  if (wan1Enabled && !isNaN(wan1Vlan)) {
    let wan1AlreadyConfigured = false;
    for (let i = 1; i <= 5; i++) {
      const pppCheck = declare(`${wanDevicePath}.WANConnectionDevice.${i}.WANPPPConnection.1.Username`, { value: Date.now() });
      if (pppCheck.size && pppCheck.value[0]) {
        wan1AlreadyConfigured = true;
        break;
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

    const vlanFieldFor = isHuawei ? "X_HW_VLAN" : (isCMCC ? "X_CMCC_VLANIDMark" : "X_ZTE-COM_VLANID");
    let bridgeWithTargetVlanExists = false;
    for (let wcd = 1; wcd <= 2 && !bridgeWithTargetVlanExists; wcd++) {
      for (let ci = 0; ci < 2 && !bridgeWithTargetVlanExists; ci++) {
        const connType = ci === 0 ? "WANIPConnection" : "WANPPPConnection";
        for (let inst = 1; inst <= 3 && !bridgeWithTargetVlanExists; inst++) {
          const scanBase = `${wanDevicePath}.WANConnectionDevice.${wcd}.${connType}.${inst}`;
          const ct = declare(`${scanBase}.ConnectionType`, { value: Date.now() });
          if (!(ct.size && ct.value && ct.value[0])) continue;
          if (String(ct.value[0]).toLowerCase().indexOf("bridg") === -1) continue;
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
    }
  }
}
