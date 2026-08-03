<?php

namespace Database\Factories;

use App\Enums\TaxComponentType;
use App\Models\TaxComponent;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxComponent>
 */
class TaxComponentFactory extends Factory
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
            'code' => strtoupper($this->faker->unique()->lexify('TAX_????')),
            'name' => $this->faker->words(2, true),
            'type' => TaxComponentType::Percentage,
            'rate' => $this->faker->randomFloat(4, 1, 15),
            'is_active' => true,
            'effective_from' => now()->startOfMonth()->toDateString(),
            'effective_to' => null,
            'description' => null,
            'created_by' => null,
        ];
    }

    public function fixed(): static
    {
        return $this->state(fn () => ['type' => TaxComponentType::Fixed]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
