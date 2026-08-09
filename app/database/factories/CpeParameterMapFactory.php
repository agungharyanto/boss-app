<?php

namespace Database\Factories;

use App\Enums\CpeParameterConversionFormula;
use App\Models\CpeParameterMap;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CpeParameterMap>
 */
class CpeParameterMapFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'oui' => strtoupper($this->faker->unique()->bothify('??####')),
            'product_class' => $this->faker->word(),
            'parameter_key' => 'rx_power_dbm',
            'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_TEST_GponInterfaceConfig.RXPower',
            'value_type' => 'xsd:int',
            'conversion_formula' => CpeParameterConversionFormula::Raw,
            'conversion_params' => null,
            'verified_at' => null,
            'verified_against_device_id' => null,
            'notes' => null,
        ];
    }

    public function verified(?string $deviceId = null): static
    {
        return $this->state(fn () => [
            'verified_at' => now(),
            'verified_against_device_id' => $deviceId ?? $this->faker->bothify('??####-????-????????'),
        ]);
    }
}
