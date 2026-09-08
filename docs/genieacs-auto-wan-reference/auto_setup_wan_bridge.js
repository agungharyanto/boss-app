//auto_setup_wan_bridge
const manufacturer = declare("DeviceID.Manufacturer", {value: 1}).value[0];
const targetVlanWan2 = 1200;
const hwSerial = declare("InternetGatewayDevice.DeviceInfo.X_HW_SerialNumber", {value: 1});
const isHuawei = (hwSerial.size && hwSerial.value[0]) || manufacturer === "Huawei Technologies Co., Ltd";

if (isHuawei) {
  const wanDevicePath = "InternetGatewayDevice.WANDevice.1";

  // PENTING: slot WANIPConnection.1 UDAH KEPAKE sama koneksi TR-069/management
  // bawaan (VID 111, ServiceList TR069) - bukan slot kosong. Bridge kita masuk
  // ke instance KEDUA: WANConnectionDevice.1.WANIPConnection.2.
  const wan1IpPath = `${wanDevicePath}.WANConnectionDevice.1.WANIPConnection`;

  // ---------- WAN2 (VLAN 1200): IP_Bridge - bikin kalau belum ada ----------
  const wan2Check = declare(`${wan1IpPath}.2.ConnectionType`, {value: Date.now()});
  const wan2AlreadyConfigured = wan2Check.size && wan2Check.value[0];
  if (!wan2AlreadyConfigured) {
    declare(`${wan1IpPath}.*`, null, {path: 2});
    commit();
    const base2Path = `${wan1IpPath}.2`;

    declare(`${base2Path}.Enable`, null, {value: true});
    declare(`${base2Path}.ConnectionType`, null, {value: "IP_Bridged"});
    declare(`${base2Path}.X_HW_VLAN`, null, {value: targetVlanWan2});
    commit();

    // Bridge - NAT off
    const nat2 = declare(`${base2Path}.NATEnabled`, {value: Date.now()});
    if (!nat2.size || nat2.value[0] != false) {
      declare(`${base2Path}.NATEnabled`, null, {value: false});
      commit();
    }
  }

  // Binding SSID2 buat VLAN 1200 - di-assert ulang tiap inform.
  const ssid2 = declare(`${wan1IpPath}.2.X_HW_LANBIND.SSID2Enable`, {value: Date.now()});
  if (!ssid2.size || ssid2.value[0] != 1) {
    declare(`${wan1IpPath}.2.X_HW_LANBIND.SSID2Enable`, null, {value: 1});
    commit();
  }
}

// Deteksi data model - sama kayak di provision auto_setup_wan_pppoe
const cmccCheck = declare("InternetGatewayDevice.X_CMCC_UserInfo.ServiceName", {value: 1});
const isCMCC = cmccCheck.size && cmccCheck.value[0] !== undefined;

const zteRx = declare("InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.RXPower", {value: 1});
const isZTEGeneric = !isCMCC && zteRx.size && zteRx.value[0] !== undefined;

if (isCMCC) {
  const wanDevicePath = "InternetGatewayDevice.WANDevice.1";

  // PENTING: di data model CMCC, WAN kedua NAMBAH INSTANCE di WANPPPConnection
  // yang sama (WANConnectionDevice.1.WANPPPConnection.2), BUKAN WANConnectionDevice.2
  // (device cuma punya 1 slot WANConnectionDevice).
  const wan1PppPath = `${wanDevicePath}.WANConnectionDevice.1.WANPPPConnection`;

  // ---------- WAN2 (VLAN 1200): PPPoE_Bridged - bikin kalau belum ada ----------
  const wan2Check = declare(`${wan1PppPath}.2.ConnectionType`, {value: Date.now()});
  const wan2AlreadyConfigured = wan2Check.size && wan2Check.value[0];
  if (!wan2AlreadyConfigured) {
    declare(`${wan1PppPath}.*`, null, {path: 2});
    commit();
    const base2Path = `${wan1PppPath}.2`;

    declare(`${base2Path}.Enable`, null, {value: true});
    declare(`${base2Path}.ConnectionType`, null, {value: "PPPoE_Bridged"});
    declare(`${base2Path}.X_CMCC_ServiceList`, null, {value: "OTHER"});
    declare(`${base2Path}.X_CMCC_VLANMode`, null, {value: 2});
    declare(`${base2Path}.X_CMCC_VLANIDMark`, null, {value: targetVlanWan2});
    commit();

    const nat2 = declare(`${base2Path}.NATEnabled`, {value: Date.now()});
    if (!nat2.size || nat2.value[0] != false) {
      declare(`${base2Path}.NATEnabled`, null, {value: false});
      commit();
    }
  }

  // Binding SSID2 buat VLAN 1200 - di-assert ulang tiap inform.
  const wan2LanTarget = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.2";
  const wan2Lan = declare(`${wan1PppPath}.2.X_CMCC_LanInterface`, {value: Date.now()});
  if (!wan2Lan.size || wan2Lan.value[0] !== wan2LanTarget) {
    declare(`${wan1PppPath}.2.X_CMCC_LanInterface`, null, {value: wan2LanTarget});
    commit();
  }
} else if (isZTEGeneric) {
  const wanDevicePath = "InternetGatewayDevice.WANDevice.1";

  // Sama kayak CMCC: nambah instance di WANConnectionDevice.1.WANPPPConnection.2,
  // bukan WANConnectionDevice.2 (device cuma punya 1 slot WANConnectionDevice).
  const wan1PppPath = `${wanDevicePath}.WANConnectionDevice.1.WANPPPConnection`;

  // ---------- WAN2 (VLAN 1200): PPPoE_Bridged - bikin kalau belum ada ----------
  const wan2Check = declare(`${wan1PppPath}.2.ConnectionType`, {value: Date.now()});
  const wan2AlreadyConfigured = wan2Check.size && wan2Check.value[0];
  if (!wan2AlreadyConfigured) {
    declare(`${wan1PppPath}.*`, null, {path: 2});
    commit();
    const base2Path = `${wan1PppPath}.2`;

    declare(`${base2Path}.Enable`, null, {value: true});
    declare(`${base2Path}.ConnectionType`, null, {value: "PPPoE_Bridged"});
    declare(`${base2Path}.X_ZTE-COM_ServiceList`, null, {value: "OTHER"});
    declare(`${base2Path}.X_ZTE-COM_VLANEnable`, null, {value: true});
    declare(`${base2Path}.X_ZTE-COM_VLANID`, null, {value: targetVlanWan2});
    commit();

    const nat2 = declare(`${base2Path}.NATEnabled`, {value: Date.now()});
    if (!nat2.size || nat2.value[0] != false) {
      declare(`${base2Path}.NATEnabled`, null, {value: false});
      commit();
    }
  }

  // Binding SSID2 buat VLAN 1200 - di-assert ulang tiap inform.
  const wan2LanTarget = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.2";
  const wan2Lan = declare(`${wan1PppPath}.2.X_ZTE-COM_LanInterface`, {value: Date.now()});
  if (!wan2Lan.size || wan2Lan.value[0] !== wan2LanTarget) {
    declare(`${wan1PppPath}.2.X_ZTE-COM_LanInterface`, null, {value: wan2LanTarget});
    commit();
  }
}