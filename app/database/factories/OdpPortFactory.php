<?php

namespace Database\Factories;

use App\Enums\OdpPortStatus;
use App\Models\Odp;
use App\Models\OdpPort;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OdpPort>
 */
class OdpPortFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'odp_id' => Odp::factory(),
            'port_number' => $this->faker->unique()->numberBetween(1, 1000),
            'status' => OdpPortStatus::Available,
            'subscription_id' => null,
        ];
    }

    public function forOdp(Odp $odp): static
    {
        return $this->state(fn () => ['odp_id' => $odp->id]);
    }

    public function reserved(): static
    {
        return $this->state(fn () => ['status' => OdpPortStatus::Reserved]);
    }

    public function used(): static
    {
        return $this->state(fn () => ['status' => OdpPortStatus::Used]);
    }

    public function damaged(): static
    {
        return $this->state(fn () => ['status' => OdpPortStatus::Damaged]);
    }
}
