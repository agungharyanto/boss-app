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
