<?php

namespace Database\Factories;

use App\Models\CpeBindingRejection;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CpeBindingRejection>
 */
class CpeBindingRejectionFactory extends Factory
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
            'tenant_id' => fn (array $attributes) => Customer::withoutGlobalScopes()->find($attributes['customer_id'])?->tenant_id,
            'genieacs_device_id' => $this->faker->unique()->regexify('[A-F0-9]{6}-[A-Za-z0-9]{10,20}-[A-Za-z0-9]{8,12}'),
            'rejected_at' => now(),
            'rejected_by' => null,
        ];
    }
}
