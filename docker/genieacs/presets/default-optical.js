const hourly = Date.now() - 3590000;

// Kept in its OWN provision, separate from "default" — GenieACS forum
// guidance (and this project's own real experience: a fault on one
// declare() halts the REST of that script for that session, only
// recovering across later sessions once the invalid path is learned) means
// a speculative vendor path faulting here must never block the
// already-reliable SSID/Hosts/MAC declares in the other provision.
//
// Ordered by REAL evidence from this deployment, not the reference list's
// original order: X_CT-COM_GponInterfaceConfig is the ALREADY-CONFIRMED
// path for this fleet (verified live against F86CE1-F663NV3a-ZICG296C2E7B
// earlier this session, RX -27.96 dBm / TX 2.46 dBm) and CT-COM's own
// UserInfo object was also seen on a CIOT device's tree — so it's placed
// first to maximize the chance it succeeds before any later fallback
// faults out the rest of the script for a given session.
declare("InternetGatewayDevice.WANDevice.*.X_CT-COM_GponInterfaceConfig.RXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_CT-COM_GponInterfaceConfig.TXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_CT-COM_EponInterfaceConfig.RXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_ZTE-COM_WANPONInterfaceConfig.RXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_ZTE-COM_WANPONInterfaceConfig.TXPower", {value: hourly});
// Kept verbatim as given (including the "Interafce" spelling) — an X_-
// prefixed vendor extension name is whatever a given firmware literally
// reports, not a standardized/spell-checked string; "fixing" the spelling
// would just make it match nothing.
declare("InternetGatewayDevice.WANDevice.*.X_GponInterafceConfig.RXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_FH_GponInterfaceConfig.RXPower", {value: hourly});
declare("InternetGatewayDevice.X_ALU_OntOpticalParam.RXPower", {value: hourly});
