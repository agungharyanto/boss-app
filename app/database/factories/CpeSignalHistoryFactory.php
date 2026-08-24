<?php

namespace Database\Factories;

use App\Models\CpeDevice;
use App\Models\CpeSignalHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CpeSignalHistory>
 */
class CpeSignalHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cpe_device_id' => CpeDevice::factory(),
            'rx_power_dbm' => $this->faker->randomFloat(2, -30, -10),
            'recorded_at' => now(),
        ];
    }
}
