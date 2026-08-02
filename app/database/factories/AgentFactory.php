<?php

namespace Database\Factories;

use App\Enums\AgentType;
use App\Models\Agent;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => null,
            'name' => $this->faker->name(),
            'phone' => $this->faker->numerify('08##########'),
            'type' => AgentType::Sales,
            'commission_rate' => null,
            'is_active' => true,
        ];
    }

    public function teknisi(): static
    {
        return $this->state(fn () => ['type' => AgentType::Teknisi]);
    }

    public function freelance(): static
    {
        return $this->state(fn () => ['type' => AgentType::Freelance]);
    }
}
