<?php

namespace Database\Factories;

use App\Models\FiberCable;
use App\Models\FiberCableWaypoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiberCableWaypoint>
 */
class FiberCableWaypointFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fiber_cable_id' => FiberCable::factory(),
            'sequence' => 1,
            'latitude' => $this->faker->latitude(-6.4, -6.0),
            'longitude' => $this->faker->longitude(106.6, 107.0),
        ];
    }
}
