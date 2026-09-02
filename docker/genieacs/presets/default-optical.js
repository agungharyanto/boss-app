const hourly = Date.now() - 3590000;

// Refresh NILAI RX/TX optik. `{value: hourly}` saja (bukan `{path}`) —
// hanya me-refresh leaf yang SUDAH diketahui GenieACS, tidak memicu
// penelusuran pohon. Provision terpisah dari "default": sebuah fault di
// sini tidak menghentikan declare SSID/Hosts/MAC yang andal di sana.
//
// HANYA objek yang GENUINELY ADA di fleet ini (dicek 2026-09-02 terhadap
// 317 device nyata) — declare `{value:}` untuk objek yang tidak ada tetap
// membebani tiap Inform dengan probe sia-sia:
//   X_CT-COM_GponInterfaceConfig  → 220 device (ZTE: M63X/GM220-S/F663NV3a/
//                                   M22X/H3-2S/M12X5G/GL-01/M32X — mayoritas)
//   X_CT-COM_EponInterfaceConfig  → 75  device
//   X_GponInterafceConfig         → 10  device (Huawei EG8141A5/HS8545M5;
//                                   ejaan "Interafce" apa adanya dari firmware)
//   X_CMCC_EponInterfaceConfig    → 7   device (ZTE F663NV9)
//
// Konversi ke dBm dilakukan di App\Services\Network\CpeParameterResolverService::
// resolveOpticalDbm() (baca pohon tersimpan, TIDAK probe): raw negatif =
// sudah dBm, raw positif = 10*log10(raw*1e-4), raw 0 = tak ada sinyal.
// Resolver itu menyimpan daftar kandidat objek yang LEBIH panjang (CU/ZTE-COM/
// FiberHome/Nokia) untuk device masa depan — aman karena cuma baca, tidak
// menambah beban Inform seperti declare di sini.

declare("InternetGatewayDevice.WANDevice.*.X_CT-COM_GponInterfaceConfig.RXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_CT-COM_GponInterfaceConfig.TXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_CT-COM_EponInterfaceConfig.RXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_CT-COM_EponInterfaceConfig.TXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_GponInterafceConfig.RXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_GponInterafceConfig.TXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_CMCC_EponInterfaceConfig.RXPower", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.X_CMCC_EponInterfaceConfig.TXPower", {value: hourly});
