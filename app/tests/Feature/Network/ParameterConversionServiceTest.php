<?php

namespace Tests\Feature\Network;

use App\Enums\CpeParameterConversionFormula;
use App\Services\Network\ParameterConversionService;
use InvalidArgumentException;
use Tests\TestCase;

class ParameterConversionServiceTest extends TestCase
{
    public function test_raw_formula_returns_value_unchanged(): void
    {
        $result = (new ParameterConversionService)->convert(15, CpeParameterConversionFormula::Raw);

        $this->assertSame(15.0, $result);
    }

    public function test_linear_formula_applies_multiplier_and_offset(): void
    {
        $result = (new ParameterConversionService)->convert(
            100,
            CpeParameterConversionFormula::Linear,
            ['multiplier' => 0.1, 'offset' => -5],
        );

        $this->assertSame(5.0, $result);
    }

    public function test_linear_formula_defaults_to_identity_when_no_params_given(): void
    {
        $result = (new ParameterConversionService)->convert(42, CpeParameterConversionFormula::Linear);

        $this->assertSame(42.0, $result);
    }

    /**
     * Real values from a real device (ZTE F663NV3.1, F86CE1-F663NV3a-
     * ZICG296C2E7B) — see CpeParameterMapSeeder's own docblock for the full
     * verification story (all four SFF-8472 DDM fields on the same object
     * landed on plausible real-world readings under this exact scale).
     */
    public function test_sff8472_optical_log10_matches_real_verified_rx_power(): void
    {
        $result = (new ParameterConversionService)->convert(
            15,
            CpeParameterConversionFormula::Sff8472OpticalLog10,
            ['scale' => 0.0001],
        );

        $this->assertEqualsWithDelta(-28.24, $result, 0.01);
    }

    public function test_sff8472_optical_log10_matches_real_verified_tx_power(): void
    {
        $result = (new ParameterConversionService)->convert(
            17100,
            CpeParameterConversionFormula::Sff8472OpticalLog10,
            ['scale' => 0.0001],
        );

        $this->assertEqualsWithDelta(2.33, $result, 0.01);
    }

    public function test_sff8472_optical_log10_rejects_zero_raw_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ParameterConversionService)->convert(
            0,
            CpeParameterConversionFormula::Sff8472OpticalLog10,
            ['scale' => 0.0001],
        );
    }

    public function test_sff8472_optical_log10_defaults_scale_to_one(): void
    {
        // scale defaults to 1.0 when omitted -> dBm = 10*log10(raw)
        $result = (new ParameterConversionService)->convert(
            10,
            CpeParameterConversionFormula::Sff8472OpticalLog10,
        );

        $this->assertEqualsWithDelta(10.0, $result, 0.001);
    }
}
