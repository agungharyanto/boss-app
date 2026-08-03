<?php

namespace Database\Factories;

use App\Enums\ResellerStatus;
use App\Models\Reseller;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Reseller>
 */
class ResellerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'tenant_id' => Tenant::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 99999),
            'email' => $this->faker->companyEmail(),
            'phone' => $this->faker->numerify('08##########'),
            'address' => $this->faker->address(),
            'status' => ResellerStatus::Active,
            'notes' => null,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => ResellerStatus::Suspended]);
    }
}
