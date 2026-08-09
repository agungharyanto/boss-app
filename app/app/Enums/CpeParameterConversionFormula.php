<?php

namespace App\Enums;

/**
 * How App\Services\Network\ParameterConversionService turns a raw TR-069
 * parameter value into a real-world unit. Extend this (not a free-text
 * string) whenever a genuinely new conversion shape is confirmed against a
 * real device — see CLAUDE.md "GenieACS Vendor Parameter Mapping (v0.7.2)"
 * for how the two below were each verified for real.
 */
enum CpeParameterConversionFormula: string
{
    // value = raw
    case Raw = 'raw';

    // value = raw * multiplier + offset (conversion_params: {"multiplier":..,"offset":..})
    case Linear = 'linear';

    // Standard SFF-8472 optical DDM power encoding: raw is a linear power
    // reading in units of `scale` mW (e.g. scale=0.0001 for 0.1 µW steps),
    // and the real-world value is the dBm conversion of that power —
    // confirmed for real against a ZTE F663NV3.1's full optical DDM object
    // (BiasCurrent/RXPower/TXPower/SupplyVottage/TransceiverTemperature all
    // matched plausible real-world values under this exact family of linear
    // scale factors; power specifically needs the extra log10 step because
    // dBm is a logarithmic unit, the other DDM fields don't).
    // conversion_params: {"scale": 0.0001}
    case Sff8472OpticalLog10 = 'sff8472_optical_log10';
}
