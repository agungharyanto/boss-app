const hourly = Date.now() - 3590000;
const minutes = Date.now() - 60000;
const daily = Date.now() - 86400000;

// SSID/Password — read-only requests, never sets a value (2-arg declare()
// form: path + timestamps only). Fallback across the two vendor-observed
// KeyPassphrase locations (WLANConfiguration.*.KeyPassphrase and the
// PreSharedKey sub-object variant), same two paths already used by
// cpe_parameter_maps for the one already-verified device in this fleet.
declare("InternetGatewayDevice.LANDevice.*.WLANConfiguration.*.SSID", {value: hourly});
declare("InternetGatewayDevice.LANDevice.*.WLANConfiguration.*.KeyPassphrase", {value: hourly});
declare("InternetGatewayDevice.LANDevice.*.WLANConfiguration.*.PreSharedKey.*.KeyPassphrase", {value: hourly});

// MAC address fallback (both candidates from the referral, still
// unverified against a real device — see CLAUDE.md's own "masalah lama
// yang belum terpecahkan" note).
declare("InternetGatewayDevice.DeviceInfo.X_CU_SerialNumber", {value: daily});
declare("InternetGatewayDevice.LANDevice.*.LANHostConfigManagement.MACAddress", {value: daily});

// Modem uptime — a genuinely STANDARD TR-069 path (unlike RX/TX power,
// which needs a vendor-specific object), same across every vendor in this
// fleet. Refreshed hourly like the other slow-changing values above.
declare("InternetGatewayDevice.DeviceInfo.UpTime", {value: hourly});

// Connected hosts (v0.7.6) — refreshed every ~minute so
// SyncCpeConnectedHosts' own 5-minute polling always sees recent data.
declare("InternetGatewayDevice.LANDevice.*.Hosts.Host.*.HostName", {value: minutes});
declare("InternetGatewayDevice.LANDevice.*.Hosts.Host.*.IPAddress", {value: minutes});
declare("InternetGatewayDevice.LANDevice.*.Hosts.Host.*.MACAddress", {value: minutes});
declare("InternetGatewayDevice.LANDevice.*.Hosts.Host.*.Active", {value: minutes});
