<?php

namespace Database\Factories;

use App\Enums\ReferrerType;
use App\Models\Referrer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Referrer>
 */
class ReferrerFactory extends Factory
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
            'type' => ReferrerType::Sales,
            'is_active' => true,
        ];
    }

    public function teknisi(): static
    {
        return $this->state(fn () => ['type' => ReferrerType::Teknisi]);
    }

    public function freelance(): static
    {
        return $this->state(fn () => ['type' => ReferrerType::Freelance]);
    }
}
