<?php

namespace Database\Factories;

use App\Models\ContainerStatsHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContainerStatsHistory>
 */
class ContainerStatsHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'container_name' => $this->faker->randomElement(['boss-app', 'boss-worker', 'genieacs-cwmp', 'librenms']),
            'cpu_percent' => $this->faker->randomFloat(2, 0, 100),
            'memory_usage_mb' => $this->faker->randomFloat(2, 10, 500),
            'memory_limit_mb' => 19768.0,
            'network_rx_bytes' => $this->faker->numberBetween(0, 1_000_000_000),
            'network_tx_bytes' => $this->faker->numberBetween(0, 1_000_000_000),
            'disk_usage_mb' => $this->faker->randomFloat(2, 0, 100),
            'recorded_at' => now(),
        ];
    }
}
