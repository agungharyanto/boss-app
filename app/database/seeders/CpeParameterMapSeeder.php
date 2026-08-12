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

            // v0.7.4 (Remote Actions) — unlike the two rows above, these are
            // plain strings, never passed through ParameterConversionService
            // (App\Services\Network\CpeActionService reads parameter_path
            // directly). conversion_formula still needs SOME value (the
            // column is NOT NULL) — Raw is the correct no-op placeholder,
            // never actually evaluated for these two keys.
            [
                'oui' => 'F86CE1',
                'product_class' => 'F663NV3a',
                'parameter_key' => 'wifi_ssid',
                'parameter_path' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                'value_type' => 'xsd:string',
                'conversion_formula' => CpeParameterConversionFormula::Raw,
                'conversion_params' => null,
                // Genuinely verified — this exact path returned a real,
                // non-empty value ('RUMAHVIA') from the same live device's
                // already-stored parameter tree (checked 2026-08-09, no new
                // connection/refreshObject triggered), not guessed from the
                // TR-069 standard alone. `_writable: true` confirmed too.
                'verified_at' => now(),
                'verified_against_device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B',
                'notes' => "Read confirmed real value 'RUMAHVIA' from the device's own stored tree. _writable=true, so SetParameterValues against this path is expected to work, but that write itself has not been tested yet (v0.7.4 Connection Request end-to-end is unverified — see CLAUDE.md).",
            ],
            [
                'oui' => 'F86CE1',
                'product_class' => 'F663NV3a',
                'parameter_key' => 'wifi_password',
                'parameter_path' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
                'value_type' => 'xsd:string',
                'conversion_formula' => CpeParameterConversionFormula::Raw,
                'conversion_params' => null,
                // Deliberately NOT verified — this path exists in the
                // device's own tree and is _writable=true, but the device
                // returns an empty string on read (common CPE security
                // behavior for passphrase fields, not a discovery gap), so
                // there is no real non-empty value to confirm against, and
                // no SetParameterValues write has been tested yet either.
                // The path is a confident inference from TR-098's standard
                // WLANConfiguration.KeyPassphrase field, not blind guesswork
                // — but "confident inference" is exactly what verified_at
                // is meant to NOT claim. Flip to verified_at once a real
                // SetParameterValues write against this exact path is
                // confirmed to actually change the device's WiFi password.
                'verified_at' => null,
                'verified_against_device_id' => null,
                'notes' => "Device's own KeyPassphrase field reads as '' (empty) — expected CPE behavior for a passphrase field, not evidence the path is wrong. PreSharedKey.1.PreSharedKey exists as an alternative path on this same device (also empty on read) if KeyPassphrase ever turns out not to be the one RouterOS-style ACS writes expect for WPA-PSK Personal; not used here without a reason to switch.",
            ],
        ];

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
