<?php

namespace Database\Factories;

use App\Models\LegacyMacCustomerMap;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegacyMacCustomerMap>
 */
class LegacyMacCustomerMapFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mac_address' => strtoupper($this->faker->unique()->macAddress()),
            'legacy_username' => $this->faker->unique()->numerify('08##########'),
        ];
    }
}
