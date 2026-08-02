<?php

namespace Database\Factories;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'address' => $this->faker->address(),
            'phone_number' => $this->faker->numerify('08##########'),
            'status' => CustomerStatus::Prospek,
        ];
    }

    public function aktif(): static
    {
        return $this->state(fn () => ['status' => CustomerStatus::Aktif]);
    }
}
