<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerTimelineEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerTimelineEntry>
 */
class CustomerTimelineEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'event_type' => 'profile_updated',
            'description' => $this->faker->sentence(),
            'changes' => null,
            'actor_id' => null,
        ];
    }
}
