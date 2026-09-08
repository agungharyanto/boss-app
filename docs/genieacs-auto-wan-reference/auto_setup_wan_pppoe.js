//auto_setup_wan_pppoe

const manufacturer = declare("DeviceID.Manufacturer", {value: 1}).value[0];
const defaultUsername = "default";
const defaultPassword = "default";
const targetVlan = 1000;
const hwSerial = declare("InternetGatewayDevice.DeviceInfo.X_HW_SerialNumber", {value: 1});
const isHuawei = (hwSerial.size && hwSerial.value[0]) || manufacturer === "Huawei Technologies Co., Ltd";

if (isHuawei) {
  const wanDevicePath = "InternetGatewayDevice.WANDevice.1";
  let alreadyConfigured = false;
  for (let i = 1; i <= 5; i++) {
    const pppCheck = declare(`${wanDevicePath}.WANConnectionDevice.${i}.WANPPPConnection.1.Username`, {value: Date.now()});
    if (pppCheck.size && pppCheck.value[0]) {
      alreadyConfigured = true;
      break;
    }
  }
  if (!alreadyConfigured) {
    const wanConnPath = `${wanDevicePath}.WANConnectionDevice.1.WANPPPConnection`;
    declare(`${wanConnPath}.*`, null, {path: 1});
    commit();
    const basePath = `${wanConnPath}.1`;
    declare(`${basePath}.Username`, null, {value: defaultUsername});
    declare(`${basePath}.Password`, null, {value: defaultPassword});
    commit();
    declare(`${basePath}.Enable`, null, {value: true});
    declare(`${basePath}.ConnectionType`, null, {value: "IP_Routed"});
    declare(`${basePath}.X_HW_VLAN`, null, {value: targetVlan});
    commit();
    const nat = declare(`${basePath}.NATEnabled`, {value: Date.now()});
    if (!nat.size || nat.value[0] != true) {
      declare(`${basePath}.NATEnabled`, null, {value: true});
      commit();
    }
    const ssid1 = declare(`${basePath}.X_HW_LANBIND.SSID1Enable`, {value: Date.now()});
    if (!ssid1.size || ssid1.value[0] != 1) {
      declare(`${basePath}.X_HW_LANBIND.SSID1Enable`, null, {value: 1});
      commit();
    }
    const lan1 = declare(`${basePath}.X_HW_LANBIND.Lan1Enable`, {value: Date.now()});
    if (!lan1.size || lan1.value[0] != 1) {
      declare(`${basePath}.X_HW_LANBIND.Lan1Enable`, null, {value: 1});
      commit();
    }
  }
}

// Deteksi data model berdasarkan config panel GenieACS UI lu sendiri:
// - CMCC data model dideteksi via X_CMCC_UserInfo.ServiceName (BUKAN via RXPower ZTE generic)
// - Baru fallback ke generic ZTE-COM kalau bukan CMCC
const cmccCheck = declare("InternetGatewayDevice.X_CMCC_UserInfo.ServiceName", {value: 1});
const isCMCC = cmccCheck.size && cmccCheck.value[0] !== undefined;

const zteRx = declare("InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.RXPower", {value: 1});
const isZTEGeneric = !isCMCC && zteRx.size && zteRx.value[0] !== undefined;

if (isCMCC) {
  const wanDevicePath = "InternetGatewayDevice.WANDevice.1";
  const wan1PppPath = `${wanDevicePath}.WANConnectionDevice.1.WANPPPConnection`;

  let alreadyConfigured = false;
  for (let i = 1; i <= 5; i++) {
    const pppCheck = declare(`${wanDevicePath}.WANConnectionDevice.${i}.WANPPPConnection.1.Username`, {value: Date.now()});
    if (pppCheck.size && pppCheck.value[0]) {
      alreadyConfigured = true;
      break;
    }
  }
  if (!alreadyConfigured) {
    declare(`${wan1PppPath}.*`, null, {path: 1});
    commit();
    const basePath = `${wan1PppPath}.1`;

    declare(`${basePath}.Username`, null, {value: defaultUsername});
    declare(`${basePath}.Password`, null, {value: defaultPassword});
    commit();

    declare(`${basePath}.Enable`, null, {value: true});
    declare(`${basePath}.ConnectionType`, null, {value: "PPPoE_Routed"});
    declare(`${basePath}.X_CMCC_ServiceList`, null, {value: "INTERNET"});
    declare(`${basePath}.X_CMCC_VLANMode`, null, {value: 2}); // 2 = enable, 1 = disable
    declare(`${basePath}.X_CMCC_VLANIDMark`, null, {value: targetVlan});
    commit();

    const nat = declare(`${basePath}.NATEnabled`, {value: Date.now()});
    if (!nat.size || nat.value[0] != true) {
      declare(`${basePath}.NATEnabled`, null, {value: true});
      commit();
    }

    declare(`${basePath}.X_CMCC_LanInterface`, null, {
      value: "InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1,InternetGatewayDevice.LANDevice.1.WLANConfiguration.1"
    });
    commit();
  }
} else if (isZTEGeneric) {
  const wanDevicePath = "InternetGatewayDevice.WANDevice.1";
  const wan1PppPath = `${wanDevicePath}.WANConnectionDevice.1.WANPPPConnection`;

  let alreadyConfigured = false;
  for (let i = 1; i <= 5; i++) {
    const pppCheck = declare(`${wanDevicePath}.WANConnectionDevice.${i}.WANPPPConnection.1.Username`, {value: Date.now()});
    if (pppCheck.size && pppCheck.value[0]) {
      alreadyConfigured = true;
      break;
    }
  }
  if (!alreadyConfigured) {
    declare(`${wan1PppPath}.*`, null, {path: 1});
    commit();
    const basePath = `${wan1PppPath}.1`;

    declare(`${basePath}.Username`, null, {value: defaultUsername});
    declare(`${basePath}.Password`, null, {value: defaultPassword});
    commit();

    declare(`${basePath}.Enable`, null, {value: true});
    declare(`${basePath}.ConnectionType`, null, {value: "PPPoE_Routed"});
    declare(`${basePath}.X_ZTE-COM_ServiceList`, null, {value: "INTERNET,TR069"});
    declare(`${basePath}.X_ZTE-COM_VLANEnable`, null, {value: true});
    declare(`${basePath}.X_ZTE-COM_VLANID`, null, {value: targetVlan});
    commit();

    const nat = declare(`${basePath}.NATEnabled`, {value: Date.now()});
    if (!nat.size || nat.value[0] != true) {
      declare(`${basePath}.NATEnabled`, null, {value: true});
      commit();
    }

    declare(`${basePath}.X_ZTE-COM_LanInterface`, null, {
      value: "InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1,InternetGatewayDevice.LANDevice.1.WLANConfiguration.1"
    });
    commit();
  }
}