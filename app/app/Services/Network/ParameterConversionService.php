<?php

namespace App\Services\Network;

use App\Enums\CpeParameterConversionFormula;
use InvalidArgumentException;

/**
 * Pure numeric conversion — no I/O, no GenieACS/DB dependency, deliberately
 * separate from CpeParameterResolverService (which does the fetching/
 * matching) so each formula can be unit-tested against known-good
 * raw/converted pairs without a device or database involved at all.
 */
class ParameterConversionService
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function convert(int|float $rawValue, CpeParameterConversionFormula $formula, array $params = []): float
    {
        return match ($formula) {
            CpeParameterConversionFormula::Raw => (float) $rawValue,
            CpeParameterConversionFormula::Linear => $this->convertLinear($rawValue, $params),
            CpeParameterConversionFormula::Sff8472OpticalLog10 => $this->convertSff8472OpticalLog10($rawValue, $params),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function convertLinear(int|float $rawValue, array $params): float
    {
        $multiplier = (float) ($params['multiplier'] ?? 1.0);
        $offset = (float) ($params['offset'] ?? 0.0);

        return $rawValue * $multiplier + $offset;
    }

    /**
     * SFF-8472 optical DDM power field → dBm. `scale` converts the raw
     * integer into a linear power reading in mW (e.g. scale=0.0001 for a
     * device reporting power in 0.1 µW steps, the family confirmed for real
     * against a ZTE F663NV3.1 — see App\Enums\CpeParameterConversionFormula
     * docblock). A raw value of 0 has no finite dBm equivalent (log10(0) is
     * undefined) — that means "no optical signal at all", not "0 dBm", so
     * this throws rather than silently returning -INF.
     *
     * @param  array<string, mixed>  $params
     */
    private function convertSff8472OpticalLog10(int|float $rawValue, array $params): float
    {
        $scale = (float) ($params['scale'] ?? 1.0);
        $milliwatts = $rawValue * $scale;

        if ($milliwatts <= 0) {
            throw new InvalidArgumentException("Cannot convert non-positive power reading ({$milliwatts} mW) to dBm — raw value {$rawValue} likely means no signal, not 0 dBm.");
        }

        return 10 * log10($milliwatts);
    }
}
