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
// path for ~90% of this fleet (ZTE-family: M63X/GM220-S/F663NV3a/M22X/
// H3-2S/M12X5G/GL-01/M32X). Every object below now also declares TXPower
// (2026-09-02) — TX was only ever pulled for a handful of models before,
// which is why "TX Power" showed empty for most of the fleet; the raw
// values ARE in the tree, they just weren't being refreshed.
//
// X_-prefixed vendor extension names (incl. the "Interafce" typo in
// X_GponInterafceConfig) are whatever a given firmware literally reports —
// "fixing" a spelling would just make it match nothing.

// --- ZTE / CT-COM family (raw SFF-8472, resolver converts: 10*log10(raw*1e-4)) ---
declare("InternetGatewayDevice.WANDevice.*.X_CT-COM_GponInterfaceConfig.RXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_CT-COM_GponInterfaceConfig.TXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_CT-COM_GponInterfaceConfig.TransceiverTemperature", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_CT-COM_EponInterfaceConfig.RXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_CT-COM_EponInterfaceConfig.TXPower", {value: hourly});

// --- ZTE CMCC family (raw SFF-8472) — F663NV9 uses this; was NOT declared before ---
declare("InternetGatewayDevice.WANDevice.*.X_CMCC_GponInterfaceConfig.RXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_CMCC_GponInterfaceConfig.TXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_CMCC_EponInterfaceConfig.RXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_CMCC_EponInterfaceConfig.TXPower", {value: hourly});

// --- ZTE-COM WANPON (raw SFF-8472) ---
declare("InternetGatewayDevice.WANDevice.*.X_ZTE-COM_WANPONInterfaceConfig.RXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_ZTE-COM_WANPONInterfaceConfig.TXPower", {value: hourly});

// --- ZTE X_CU OpticalTransceiver (raw SFF-8472) ---
declare("InternetGatewayDevice.WANDevice.*.X_CU_WANEPONInterfaceConfig.OpticalTransceiver.RXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_CU_WANEPONInterfaceConfig.OpticalTransceiver.TXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_CU_WANGPONInterfaceConfig.OpticalTransceiver.RXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_CU_WANGPONInterfaceConfig.OpticalTransceiver.TXPower", {value: hourly});

// --- Huawei (already dBm, resolver uses raw) ---
declare("InternetGatewayDevice.WANDevice.*.X_GponInterafceConfig.RXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_GponInterafceConfig.TXPower", {value: hourly});

// --- FiberHome (already dBm) ---
declare("InternetGatewayDevice.WANDevice.*.X_FH_GponInterfaceConfig.RXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_FH_GponInterfaceConfig.TXPower", {value: hourly});

// --- Nokia/ALU (already dBm, NOT under WANDevice) ---
declare("InternetGatewayDevice.X_ALU_OntOpticalParam.RXPower", {value: hourly});
declare("InternetGatewayDevice.X_ALU_OntOpticalParam.TXPower", {value: hourly});
