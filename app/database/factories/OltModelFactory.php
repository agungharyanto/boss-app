<?php

namespace Database\Factories;

use App\Enums\OltPonType;
use App\Models\OltManufacturer;
use App\Models\OltModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OltModel>
 */
class OltModelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'olt_manufacturer_id' => OltManufacturer::factory(),
            'name' => $this->faker->unique()->bothify('OLT-####??'),
            'supported_pon_type' => $this->faker->randomElement(OltPonType::cases()),
        ];
    }
}
