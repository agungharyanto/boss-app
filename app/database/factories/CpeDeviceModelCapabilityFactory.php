<?php

namespace Database\Factories;

use App\Models\CpeDeviceModelCapability;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CpeDeviceModelCapability>
 */
class CpeDeviceModelCapabilityFactory extends Factory
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
            'max_ssid_slots' => 4,
            'supports_5g' => false,
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
