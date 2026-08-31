<?php

namespace Database\Factories;

use App\Enums\FiberCoreStatus;
use App\Models\FiberCable;
use App\Models\FiberCore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiberCore>
 */
class FiberCoreFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fiber_cable_id' => FiberCable::factory(),
            'tube_number' => 1,
            'core_number_in_tube' => $this->faker->numberBetween(1, 12),
            'tube_color' => null,
            'core_color' => null,
            'status' => FiberCoreStatus::Spare,
        ];
    }
}
