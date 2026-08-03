<?php

namespace Database\Factories;

use App\Enums\ResellerPackagePricingStatus;
use App\Models\Reseller;
use App\Models\ResellerPackagePricing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResellerPackagePricing>
 */
class ResellerPackagePricingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reseller_id' => Reseller::factory(),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'price' => $this->faker->randomFloat(2, 100000, 1000000),
            'is_custom' => false,
            'status' => ResellerPackagePricingStatus::Active,
        ];
    }

    public function custom(): static
    {
        return $this->state(fn () => ['is_custom' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => ResellerPackagePricingStatus::Inactive]);
    }
}
