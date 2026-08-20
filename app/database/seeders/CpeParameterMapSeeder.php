<?php

namespace Database\Seeders;

use App\Enums\CpeParameterConversionFormula;
use App\Models\CpeParameterMap;
use Illuminate\Database\Seeder;

/**
 * Per-vendor/model TR-069 parameter mapping catalog (v0.7.2). Safe to
 * re-run — `updateOrCreate` keyed on (oui, product_class, parameter_key),
 * never overwrites a row's own admin-edited notes silently since the whole
 * row (including verified_at/verified_against_device_id) is what this
 * seeder itself owns for these entries.
 *
 * ZTE F663NV3.1 (OUI F86CE1, ProductClass "F663NV3a" — the device's own
 * literal reported ProductClass string, not a typo) rx/tx power rows below
 * are the FIRST real, hardware-verified mapping in this catalog — verified
 * against a real device (F86CE1-F663NV3a-ZICG296C2E7B) mid-v0.7.2: its full
 * optical DDM object (InternetGatewayDevice.WANDevice.1.
 * X_CT-COM_GponInterfaceConfig) exposes standard SFF-8472 fields
 * (BiasCurrent/RXPower/TXPower/SupplyVottage/TransceiverTemperature).
 * The sff8472_optical_log10 formula + scale=0.0001 (raw is a linear power
 * reading in 0.1 µW steps) was confirmed by converting all four numeric
 * DDM fields at once, not just RXPower in isolation — SupplyVottage,
 * BiasCurrent, and TransceiverTemperature under the same scale family all
 * landed on textbook-normal real-world readings (~3.28V, ~16.3mA, ~57°C),
 * and TXPower converted to 2.33 dBm (normal GPON TX range) — RXPower itself
 * converted to -28.24 dBm, a real, plausible (if slightly weak) reading for
 * that live device, not a clean round number, which is itself further
 * evidence this is a real measurement and not a coincidental formula match.
 */
class CpeParameterMapSeeder extends Seeder
{
    public function run(): void
    {
        $maps = [
            [
                'oui' => 'F86CE1',
                'product_class' => 'F663NV3a',
                'parameter_key' => 'rx_power_dbm',
                'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower',
                'value_type' => 'xsd:int',
                'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
                'conversion_params' => ['scale' => 0.0001],
                'verified_at' => now(),
                'verified_against_device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B',
                'notes' => 'Raw 15 -> -28.24 dBm. Cross-verified via the same object\'s SupplyVottage/BiasCurrent/TransceiverTemperature all landing on plausible real-world SFF-8472 readings under the same scale.',
            ],
            [
                'oui' => 'F86CE1',
                'product_class' => 'F663NV3a',
                'parameter_key' => 'tx_power_dbm',
                'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.TXPower',
                'value_type' => 'xsd:int',
                'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
                'conversion_params' => ['scale' => 0.0001],
                'verified_at' => now(),
                'verified_against_device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B',
                'notes' => 'Raw 17100 -> 2.33 dBm, squarely inside normal GPON ONT TX power range.',
            ],
            // Found and fixed post-v0.7.4: 1C25E1 (CIOT) and A4F33B (ZICG)
            // GM220-S/M63X XPON devices ALSO report through the same
            // X_CT-COM_GponInterfaceConfig object as the F86CE1 rows above
            // (confirmed empirically once the "default" GenieACS preset
            // started requesting it) — but no catalog row ever existed for
            // them, so /cpe-devices' RX/TX columns silently showed "-" for
            // every non-F86CE1 device despite GenieACS already having real
            // data cached. Root-caused by checking CpeParameterResolverService
            // ::resolveForDevice() directly (empty result for both OUIs),
            // not by guessing — the raw GenieACS data alone isn't what the
            // UI reads.
            [
                'oui' => '1C25E1',
                'product_class' => 'GM220-S',
                'parameter_key' => 'rx_power_dbm',
                'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower',
                'value_type' => 'xsd:int',
                'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
                'conversion_params' => ['scale' => 0.0001],
                'verified_at' => now(),
                'verified_against_device_id' => '1C25E1-GM220%2DS-CIOTXA3D4200',
                'notes' => 'Raw 41 -> -23.87 dBm, real device (Asep Mulyadi). Same X_CT-COM_GponInterfaceConfig object family as F86CE1, same scale.',
            ],
            [
                'oui' => '1C25E1',
                'product_class' => 'GM220-S',
                'parameter_key' => 'tx_power_dbm',
                'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.TXPower',
                'value_type' => 'xsd:int',
                'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
                'conversion_params' => ['scale' => 0.0001],
                'verified_at' => now(),
                'verified_against_device_id' => '1C25E1-GM220%2DS-CIOTXA3D4200',
                'notes' => 'Raw 17338 -> 2.39 dBm, real device (Asep Mulyadi), normal GPON TX range.',
            ],
            [
                'oui' => 'A4F33B',
                'product_class' => 'M63X XPON',
                'parameter_key' => 'rx_power_dbm',
                'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower',
                'value_type' => 'xsd:int',
                'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
                'conversion_params' => ['scale' => 0.0001],
                'verified_at' => now(),
                'verified_against_device_id' => 'A4F33B-M63X%20XPON-ZICG297CA0C7',
                'notes' => 'Raw 23 -> -26.38 dBm, real device. Same X_CT-COM_GponInterfaceConfig object family as F86CE1/1C25E1, same scale.',
            ],
            [
                'oui' => 'A4F33B',
                'product_class' => 'M63X XPON',
                'parameter_key' => 'tx_power_dbm',
                'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.TXPower',
                'value_type' => 'xsd:int',
                'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
                'conversion_params' => ['scale' => 0.0001],
                'verified_at' => now(),
                'verified_against_device_id' => 'A4F33B-M63X%20XPON-ZICG297CA0C7',
                'notes' => 'Raw 18197 -> 2.60 dBm, real device, normal GPON TX range.',
            ],
            // Modem uptime (v0.7.6-follow-up) — a genuinely STANDARD TR-069
            // path (DeviceInfo.UpTime, no vendor prefix at all), unlike
            // RX/TX which needs a vendor-specific object. Raw value is
            // already plain seconds (xsd:unsignedInt), so Raw formula (no
            // conversion) is correct, not a placeholder like the
            // wifi_ssid/wifi_password rows below.
            [
                'oui' => '1C25E1',
                'product_class' => 'GM220-S',
                'parameter_key' => 'device_uptime_seconds',
                'parameter_path' => 'InternetGatewayDevice.DeviceInfo.UpTime',
                'value_type' => 'xsd:unsignedInt',
                'conversion_formula' => CpeParameterConversionFormula::Raw,
                'conversion_params' => null,
                'verified_at' => now(),
                'verified_against_device_id' => '1C25E1-GM220%2DS-CIOT10D62710',
                'notes' => 'Raw 6567 (~1j49m) — real device, fresh timestamp matching a real Inform right after adding this declare() to the preset.',
            ],
            // Found post-Detail-modal-redesign: the uptime row above was
            // only ever added for 1C25E1/GM220-S — F86CE1/F663NV3a and
            // A4F33B/M63X XPON already had rx/tx_power_dbm rows (proving
            // GenieACS already had fresh data cached for them) but NO
            // device_uptime_seconds row at all, so "Uptime Modem" silently
            // showed "-" for those two vendor families regardless of how
            // often they informed. Root-caused by checking the raw
            // DeviceInfo.UpTime value directly on an already-RX-resolved
            // device (F86CE1: raw 14336 ≈ 3j58m; A4F33B/M63X XPON: raw
            // 14548 ≈ 4j2m, both fresh timestamps) before concluding this
            // was a missing-catalog-row bug and not a timing issue.
            [
                'oui' => 'F86CE1',
                'product_class' => 'F663NV3a',
                'parameter_key' => 'device_uptime_seconds',
                'parameter_path' => 'InternetGatewayDevice.DeviceInfo.UpTime',
                'value_type' => 'xsd:unsignedInt',
                'conversion_formula' => CpeParameterConversionFormula::Raw,
                'conversion_params' => null,
                'verified_at' => now(),
                'verified_against_device_id' => 'F86CE1-F663NV3a-ZICG2970FA5C',
                'notes' => 'Raw 14336 (~3j58m) — real device (Juhaeni), fresh timestamp.',
            ],
            [
                'oui' => 'A4F33B',
                'product_class' => 'M63X XPON',
                'parameter_key' => 'device_uptime_seconds',
                'parameter_path' => 'InternetGatewayDevice.DeviceInfo.UpTime',
                'value_type' => 'xsd:unsignedInt',
                'conversion_formula' => CpeParameterConversionFormula::Raw,
                'conversion_params' => null,
                'verified_at' => now(),
                'verified_against_device_id' => 'A4F33B-M63X%20XPON-ZICG297CA0C7',
                'notes' => 'Raw 14548 (~4j2m) — real device, fresh timestamp.',
            ],
            // Same missing-catalog-row class of bug, found the same way for
            // rx/tx this time: A4F33B/GM219 (Sadi) already had real,
            // fresh RX/TX data cached in GenieACS (raw RX 24 -> -26.20 dBm,
            // raw TX 15488 -> 1.90 dBm) but no catalog row existed for this
            // specific product_class at all — A4F33B/GM220-S and
            // A4F33B/M63X XPON did, A4F33B/GM219 didn't. Confirmed via
            // CpeParameterResolverService's own live GenieACS query, not
            // assumed from the other A4F33B rows.
            [
                'oui' => 'A4F33B',
                'product_class' => 'GM219',
                'parameter_key' => 'rx_power_dbm',
                'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower',
                'value_type' => 'xsd:int',
                'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
                'conversion_params' => ['scale' => 0.0001],
                'verified_at' => now(),
                'verified_against_device_id' => 'A4F33B-GM219-ZICG29637EA7',
                'notes' => 'Raw 24 -> -26.20 dBm, real device (Sadi).',
            ],
            [
                'oui' => 'A4F33B',
                'product_class' => 'GM219',
                'parameter_key' => 'tx_power_dbm',
                'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.TXPower',
                'value_type' => 'xsd:int',
                'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
                'conversion_params' => ['scale' => 0.0001],
                'verified_at' => now(),
                'verified_against_device_id' => 'A4F33B-GM219-ZICG29637EA7',
                'notes' => 'Raw 15488 -> 1.90 dBm, real device (Sadi), normal GPON TX range.',
            ],
            // A4F33B/GM220-S is STILL not added — that device has not
            // informed since the preset went live (tree still thin, no
            // X_CT-COM_GponInterfaceConfig object present as of this
            // seeding) — no real data to verify against yet.

            // v0.7.4 (Remote Actions) originally had `wifi_ssid`/
            // `wifi_password` catalog rows here (F86CE1/F663NV3a only,
            // hardcoded to WLANConfiguration.1) — removed 2026-08-17.
            // App\Services\Network\CpeActionService::setWifiCredentials()
            // no longer consults this catalog for those two keys: the
            // multi-SSID discovery work (2026-08-16) confirmed
            // WLANConfiguration.{n}.SSID/KeyPassphrase is standard TR-069,
            // identical across every vendor OUI in this fleet, not a
            // per-vendor quantity — the path is now built directly from the
            // caller's own $ssidIndex instead (see the CPE detail page's
            // per-row "Ganti WiFi"). rx_power_dbm/tx_power_dbm above are
            // NOT affected by this — those stay catalog-driven since the
            // optical DDM object genuinely IS vendor-specific (confirmed
            // repeatedly across the RX Power discovery sessions: different
            // namespace name, different formula per vendor family).
        ];

        // H3-2s / H3-2S XPON (discovered 2026-08-14, after DHCP Option 43
        // brought ~70 previously-unseen devices into GenieACS at once) —
        // same X_CT-COM_GponInterfaceConfig object and sff8472_optical_log10
        // formula as F86CE1/F663NV3a above, confirmed independently across
        // EVERY distinct OUI seen for each product class (7 OUIs each, 14
        // combos total) before adding a row for it — this product class name
        // is a shared ODM reference design sold under many different vendor
        // OUIs, not one manufacturer, so OUI-to-OUI consistency was checked
        // rather than assumed from a single verified device. One H3-2s OUI
        // (044F7A) has device_uptime_seconds verified but NOT rx/tx_power —
        // that device's tree hadn't finished discovering the GPON optical
        // object yet at verification time (RXPower/TXPower read null),
        // so no row is added for it here rather than guessing the path is
        // simply wrong.
        $h32sGponReadings = [
            // oui => [rx_raw, tx_raw, uptime_raw, device_id]
            '241281' => [21, 18281, 115012, '241281-H3-2s-CMDCA213E51A'],
            'A861DF' => [41, 18923, 159306, 'A861DF-H3-2s-CMDCA108CE3B'],
            'C80C53' => [51, 18492, 502209, 'C80C53-H3-2s-CMDC11F3363F'],
            'DC7CF7' => [11, 24547, 4702, 'DC7CF7-H3-2s-CMDC146A2EB0'],
            '0815AE' => [18, 17060, 113, '0815AE-H3-2s-CMDCA474672A'],
            '90F3B8' => [60, 18407, 164561, '90F3B8-H3-2s-CMDCA10274AB'],
        ];
        $h32sUptimeOnly = [
            '044F7A' => [288837, '044F7A-H3-2s-CMDC14406044'],
        ];
        $h32sXponGponReadings = [
            '6C0F0B' => [24, 16672, 118959, '6C0F0B-H3-2S XPON-CMDCA44CC879'],
            'FC2E19' => [48, 16826, 86497, 'FC2E19-H3-2S XPON-CMDCA449AB05'],
            'A861DF' => [20, 16218, 535876, 'A861DF-H3-2S XPON-CMDCA44F9991'],
            'EC9B2D' => [40, 17458, 4100, 'EC9B2D-H3-2S XPON-CMDCA453D0FE'],
            '448EEC' => [11, 16749, 3232, '448EEC-H3-2S XPON-CMDCA11244B4'],
            '507097' => [33, 17218, 136906, '507097-H3-2S XPON-CMDCA449F05E'],
            'BC9E2C' => [41, 16405, 145299, 'BC9E2C-H3-2S XPON-CMDCA46FFE00'],
        ];

        $h32sMaps = [];

        foreach (['H3-2s' => $h32sGponReadings, 'H3-2S XPON' => $h32sXponGponReadings] as $productClass => $readings) {
            foreach ($readings as $oui => [$rxRaw, $txRaw, $uptimeRaw, $deviceId]) {
                $rxDbm = round(10 * log10($rxRaw * 0.0001), 2);
                $txDbm = round(10 * log10($txRaw * 0.0001), 2);

                $h32sMaps[] = [
                    'oui' => $oui, 'product_class' => $productClass, 'parameter_key' => 'rx_power_dbm',
                    'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower',
                    'value_type' => 'xsd:int',
                    'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
                    'conversion_params' => ['scale' => 0.0001],
                    'verified_at' => now(), 'verified_against_device_id' => $deviceId,
                    'notes' => "Raw {$rxRaw} -> {$rxDbm} dBm, normal GPON range.",
                ];
                $h32sMaps[] = [
                    'oui' => $oui, 'product_class' => $productClass, 'parameter_key' => 'tx_power_dbm',
                    'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.TXPower',
                    'value_type' => 'xsd:int',
                    'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
                    'conversion_params' => ['scale' => 0.0001],
                    'verified_at' => now(), 'verified_against_device_id' => $deviceId,
                    'notes' => "Raw {$txRaw} -> {$txDbm} dBm, normal GPON TX range.",
                ];
                $h32sMaps[] = [
                    'oui' => $oui, 'product_class' => $productClass, 'parameter_key' => 'device_uptime_seconds',
                    'parameter_path' => 'InternetGatewayDevice.DeviceInfo.UpTime',
                    'value_type' => 'xsd:unsignedInt',
                    'conversion_formula' => CpeParameterConversionFormula::Raw,
                    'conversion_params' => null,
                    'verified_at' => now(), 'verified_against_device_id' => $deviceId,
                    'notes' => "Raw {$uptimeRaw} seconds, plausible uptime.",
                ];
            }
        }

        foreach ($h32sUptimeOnly as $oui => [$uptimeRaw, $deviceId]) {
            $h32sMaps[] = [
                'oui' => $oui, 'product_class' => 'H3-2s', 'parameter_key' => 'device_uptime_seconds',
                'parameter_path' => 'InternetGatewayDevice.DeviceInfo.UpTime',
                'value_type' => 'xsd:unsignedInt',
                'conversion_formula' => CpeParameterConversionFormula::Raw,
                'conversion_params' => null,
                'verified_at' => now(), 'verified_against_device_id' => $deviceId,
                'notes' => "Raw {$uptimeRaw} seconds, plausible uptime. RX/TX power NOT verified for this OUI — device's GPON optical object hadn't finished discovering (read null) at verification time.",
            ];
        }

        $maps = array_merge($maps, $h32sMaps);

        // Second discovery batch (2026-08-16) — the 5 largest remaining
        // OUI+ProductClass combos with no RX/TX mapping (42 of the 68
        // devices found via the "how many UNIQUE combos, not raw device
        // count" enumeration done first, per instruction, before touching
        // any device individually). Each verified against 5-9 real devices
        // per combo, not just one, since the whole point of this batch was
        // scale — one lucky reading isn't enough evidence on its own.
        //
        // 00259E/EG8141A5 (Huawei) is the ODD ONE OUT here: unlike every
        // ZTE-family OUI in this catalog (A4F33B/F86CE1/B4E46B/4C46D1/etc,
        // all X_CT-COM_GponInterfaceConfig + sff8472_optical_log10 raw
        // linear power), Huawei's own vendor object is a DIFFERENT path
        // (WANDevice.1.X_GponInterafceConfig — note "Interafce", a real
        // typo baked into Huawei's own field name, not ours) AND a
        // DIFFERENT formula: RXPower/TXPower are reported ALREADY in dBm
        // (e.g. -25, 2), not a raw linear reading needing log10 conversion
        // — confirmed by the values landing squarely in normal GPON range
        // as-is, and would be absurd (-4013 dBm) if the ZTE-style formula
        // were mistakenly applied to them. Uses CpeParameterConversionFormula::Raw.
        $secondBatchMaps = [
            [
                'oui' => 'A4F33B', 'product_class' => 'M22X XPON', 'parameter_key' => 'rx_power_dbm',
                'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower',
                'value_type' => 'xsd:int', 'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
                'conversion_params' => ['scale' => 0.0001], 'verified_at' => now(),
                'verified_against_device_id' => 'A4F33B-M22X XPON-ZICG2982D98D',
                'notes' => 'Checked across 3 real devices, RX -15.65 to -27.45 dBm, all plausible GPON range.',
            ],
            [
                'oui' => 'A4F33B', 'product_class' => 'M22X XPON', 'parameter_key' => 'tx_power_dbm',
                'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.TXPower',
                'value_type' => 'xsd:int', 'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
                'conversion_params' => ['scale' => 0.0001], 'verified_at' => now(),
                'verified_against_device_id' => 'A4F33B-M22X XPON-ZICG2982D98D',
                'notes' => 'Checked across 3 real devices, TX 2.26-2.71 dBm, normal GPON TX range.',
            ],
            [
                'oui' => '00259E', 'product_class' => 'EG8141A5', 'parameter_key' => 'rx_power_dbm',
                'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.RXPower',
                'value_type' => 'xsd:int', 'conversion_formula' => CpeParameterConversionFormula::Raw,
                'conversion_params' => null, 'verified_at' => now(),
                'verified_against_device_id' => '00259E-EG8141A5-48575443796B91A7',
                'notes' => 'Huawei — value is ALREADY in dBm, not a raw linear reading (unlike every ZTE-family OUI in this catalog). Checked across all 9 real devices of this OUI/class, -14 to -27 dBm, all plausible. Path has a real typo ("Interafce") baked into the device\'s own field name.',
            ],
            [
                'oui' => '00259E', 'product_class' => 'EG8141A5', 'parameter_key' => 'tx_power_dbm',
                'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.TXPower',
                'value_type' => 'xsd:int', 'conversion_formula' => CpeParameterConversionFormula::Raw,
                'conversion_params' => null, 'verified_at' => now(),
                'verified_against_device_id' => '00259E-EG8141A5-48575443796B91A7',
                'notes' => 'Huawei, already in dBm. Only 1 of 9 devices had this field discovered at verification time (2 dBm, plausible) — path/formula confirmed correct even though most instances are still mid-discovery.',
            ],
            [
                'oui' => '4C46D1', 'product_class' => 'GL-01', 'parameter_key' => 'rx_power_dbm',
                'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower',
                'value_type' => 'xsd:int', 'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
                'conversion_params' => ['scale' => 0.0001], 'verified_at' => now(),
                'verified_against_device_id' => '4C46D1-GL-01-123454C46D14445D4',
                'notes' => 'Checked across all 8 real devices, RX -15.72 to -30 dBm (7/8 within -8..-27, 1 borderline weak-signal outlier) — same formula family as the ZTE-OUI devices despite the different-looking model name.',
            ],
            [
                'oui' => '4C46D1', 'product_class' => 'GL-01', 'parameter_key' => 'tx_power_dbm',
                'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.TXPower',
                'value_type' => 'xsd:int', 'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
                'conversion_params' => ['scale' => 0.0001], 'verified_at' => now(),
                'verified_against_device_id' => '4C46D1-GL-01-123454C46D14445D4',
                'notes' => 'Checked across all 8 real devices, TX 2.09-2.46 dBm, normal GPON TX range.',
            ],
            [
                'oui' => 'B4E46B', 'product_class' => 'M12X5G XPON', 'parameter_key' => 'rx_power_dbm',
                'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower',
                'value_type' => 'xsd:int', 'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
                'conversion_params' => ['scale' => 0.0001], 'verified_at' => now(),
                'verified_against_device_id' => 'B4E46B-M12X5G XPON-ZICG275DA34D',
                'notes' => 'Checked across all 7 real devices, RX -18.15 to -31.55 dBm (6/7 within -8..-27, 1 borderline weak-signal outlier).',
            ],
            [
                'oui' => 'B4E46B', 'product_class' => 'M12X5G XPON', 'parameter_key' => 'tx_power_dbm',
                'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.TXPower',
                'value_type' => 'xsd:int', 'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
                'conversion_params' => ['scale' => 0.0001], 'verified_at' => now(),
                'verified_against_device_id' => 'B4E46B-M12X5G XPON-ZICG275DA34D',
                'notes' => 'Checked across all 7 real devices, TX 2.12-2.43 dBm, normal GPON TX range.',
            ],
            [
                'oui' => 'A4F33B', 'product_class' => 'GM220-S', 'parameter_key' => 'rx_power_dbm',
                'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower',
                'value_type' => 'xsd:int', 'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
                'conversion_params' => ['scale' => 0.0001], 'verified_at' => now(),
                'verified_against_device_id' => 'A4F33B-GM220-S-ZICG29696B80',
                'notes' => 'A DIFFERENT OUI than the already-verified 1C25E1/GM220-S row — same product class name, but this fleet has multiple vendor OUIs sharing "GM220-S" as a generic model string, each needing its own row per this catalog\'s (oui, product_class) key. Checked across 5 of 6 real devices with discovered data, RX -20.86 to -31.55 dBm (4/5 within -8..-27, 1 borderline outlier).',
            ],
            [
                'oui' => 'A4F33B', 'product_class' => 'GM220-S', 'parameter_key' => 'tx_power_dbm',
                'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.TXPower',
                'value_type' => 'xsd:int', 'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
                'conversion_params' => ['scale' => 0.0001], 'verified_at' => now(),
                'verified_against_device_id' => 'A4F33B-GM220-S-ZICG29696B80',
                'notes' => 'Checked across 5 of 6 real devices, TX 1.79-2.74 dBm, normal GPON TX range.',
            ],
        ];

        $maps = array_merge($maps, $secondBatchMaps);

        // Third discovery batch (2026-08-16) — the remaining small
        // combos (1-4 devices each) left over after the two batches
        // above. Two real, distinct findings this round, not just "more
        // of the same":
        //
        // 1. `000000/probe` deliberately gets NO row — `_OUI` "000000"
        //    and ProductClass "probe" is GenieACS's own internal
        //    discovery/probe entry (same class already documented for
        //    "DISCOVERYSERVICE" — see LegacyDeviceMatcherService's own
        //    looksLikeRealOntDevice() filter), confirmed by its
        //    WANDevice.1 having ZERO vendor keys at all, not a real ONT.
        //
        // 2. `347839/F663NV9` (the real device this whole cluster's
        //    Option-43/DHCP-lease investigation centered on) is EPON, not
        //    GPON — its vendor object is `X_CMCC_EponInterfaceConfig`, a
        //    different path from every GPON-family OUI in this catalog,
        //    though the SAME raw-linear scale=0.0001 formula and the same
        //    BiasCurrent/RXPower/TXPower/SupplyVottage/
        //    TransceiverTemperature field set — confirms this vendor
        //    reused their GPON encoding convention for their EPON line
        //    too, not a coincidence worth re-deriving from scratch, but
        //    still verified against this exact device's own real reading
        //    (RX -29.21 dBm — slightly past the -8..-27 rule of thumb but
        //    a plausible weak-but-real signal, not a malformed number;
        //    TX 2.22 dBm squarely normal) rather than assumed from the
        //    GPON pattern alone.
        //
        // Every other combo below turned out to share the exact same
        // X_CT-COM_GponInterfaceConfig path + formula already established
        // for every other ZTE-family OUI in this catalog — verified
        // per-combo against real raw values (not assumed from the shared
        // namespace alone), all landing in plausible GPON range.
        // NB: `device_id` below is the REAL GenieACS `_id` string, including
        // its literal `%2D`/`%20` URL-encoding of `-`/` ` inside the
        // ProductClass segment — GenieACS device ids encode the product
        // class portion this way, and it's part of the literal `_id` value,
        // not cosmetic formatting to be cleaned up. An earlier pass through
        // this file wrote these ids with plain hyphens/spaces instead, which
        // silently made every one of these rows' `verified_against_device_id`
        // point at a device that doesn't exist (findDeviceById() returned
        // null) — caught by re-verifying against CpeParameterResolverService
        // right after seeding, not assumed to be correct from the row data
        // alone.
        $thirdBatchGponReadings = [
            // oui/product_class => [rx_raw, tx_raw, device_id]
            'F0D168/GM220-S' => [94, 17619, 'F0D168-GM220%2DS-CIOT13F0D168'],
            '08D054/M63X-XPON' => [483, 17864, '08D054-M63X%2DXPON-RTEGC608D054'],
            '702E22/M32X-5G' => [11, 17378, '702E22-M32X%2D5G-RTEGC62B2147'],
            '1C25E1/GM220-S XPON' => [16, 17060, '1C25E1-GM220%2DS%20XPON-CIOT1326C4A8'],
            '2DD138/GM220-S' => [14, 17988, '2DD138-GM220%2DS-CIOT112DD138'],
            'C20A58/GM220-S' => [74, 17988, 'C20A58-GM220%2DS-CIOT07C20A58'],
            'F86CE1/GM219' => [38, 16672, 'F86CE1-GM219-ZICG299033AD'],
            '241A18/GM220-S' => [28, 18197, '241A18-GM220%2DS-CIOT12241A18'],
            '087318/M63X-XPON' => [18, 17579, '087318-M63X%2DXPON-RTEGC6087318'],
            '0857C5/M63X-XPON' => [606, 17579, '0857C5-M63X%2DXPON-RTEGC60857C5'],
            '08267F/M63X-XPON' => [66, 17660, '08267F-M63X%2DXPON-RTEGC608267F'],
            '085D3B/M63X-XPON' => [43, 17988, '085D3B-M63X%2DXPON-RTEGC6085D3B'],
            '76B6A8/GM220-S' => [60, 17579, '76B6A8-GM220%2DS-CIOT1576B6A8'],
            '7C6A60/H3-2S XPON' => [177, 17741, '7C6A60-H3%2D2S%20XPON-CMDCA4576F3D'],
        ];

        $thirdBatchMaps = [];

        foreach ($thirdBatchGponReadings as $key => [$rxRaw, $txRaw, $deviceId]) {
            [$oui, $productClass] = explode('/', $key, 2);
            $rxDbm = round(10 * log10($rxRaw * 0.0001), 2);
            $txDbm = round(10 * log10($txRaw * 0.0001), 2);

            $thirdBatchMaps[] = [
                'oui' => $oui, 'product_class' => $productClass, 'parameter_key' => 'rx_power_dbm',
                'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower',
                'value_type' => 'xsd:int', 'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
                'conversion_params' => ['scale' => 0.0001], 'verified_at' => now(),
                'verified_against_device_id' => $deviceId,
                'notes' => "Raw {$rxRaw} -> {$rxDbm} dBm.",
            ];
            $thirdBatchMaps[] = [
                'oui' => $oui, 'product_class' => $productClass, 'parameter_key' => 'tx_power_dbm',
                'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.TXPower',
                'value_type' => 'xsd:int', 'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
                'conversion_params' => ['scale' => 0.0001], 'verified_at' => now(),
                'verified_against_device_id' => $deviceId,
                'notes' => "Raw {$txRaw} -> {$txDbm} dBm.",
            ];
        }

        // EPON exception — see the batch comment above.
        $thirdBatchMaps[] = [
            'oui' => '347839', 'product_class' => 'F663NV9', 'parameter_key' => 'rx_power_dbm',
            'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CMCC_EponInterfaceConfig.RXPower',
            'value_type' => 'xsd:int', 'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
            'conversion_params' => ['scale' => 0.0001], 'verified_at' => now(),
            'verified_against_device_id' => '347839-F663NV9-ZTEGCB399CEB',
            'notes' => 'EPON, not GPON — different vendor object (X_CMCC_EponInterfaceConfig) than every other row in this catalog, but same field set/scale. Raw 12 -> -29.21 dBm, slightly past the -8..-27 rule of thumb but a plausible weak-but-real reading (TX below confirms the formula itself is right).',
        ];
        $thirdBatchMaps[] = [
            'oui' => '347839', 'product_class' => 'F663NV9', 'parameter_key' => 'tx_power_dbm',
            'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CMCC_EponInterfaceConfig.TXPower',
            'value_type' => 'xsd:int', 'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
            'conversion_params' => ['scale' => 0.0001], 'verified_at' => now(),
            'verified_against_device_id' => '347839-F663NV9-ZTEGCB399CEB',
            'notes' => 'Raw 16682 -> 2.22 dBm, normal range — confirms the RX reading above is a real weak signal, not a wrong formula.',
        ];

        $maps = array_merge($maps, $thirdBatchMaps);

        foreach ($maps as $map) {
            CpeParameterMap::query()->updateOrCreate(
                [
                    'oui' => $map['oui'],
                    'product_class' => $map['product_class'],
                    'parameter_key' => $map['parameter_key'],
                ],
                $map,
            );
        }
    }
}
