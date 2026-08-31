<?php

namespace Database\Factories;

use App\Enums\FiberAccessoryType;
use App\Models\FiberAccessory;
use App\Models\FiberCable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiberAccessory>
 */
class FiberAccessoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fiber_cable_id' => FiberCable::factory(),
            'splitter_id' => null,
            'accessory_type' => FiberAccessoryType::Connector,
            'expected_loss_db' => 0.25,
            'measured_loss_db' => null,
            'location_note' => null,
        ];
    }
}
