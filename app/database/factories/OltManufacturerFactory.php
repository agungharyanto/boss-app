<?php

namespace Database\Factories;

use App\Models\OltManufacturer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OltManufacturer>
 */
class OltManufacturerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company(),
        ];
    }
}
