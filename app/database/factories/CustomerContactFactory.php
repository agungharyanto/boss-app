<?php

namespace Database\Factories;

use App\Enums\ContactAccessLevel;
use App\Models\Customer;
use App\Models\CustomerContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerContact>
 */
class CustomerContactFactory extends Factory
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
            // Must match the parent customer's tenant, not an independent random tenant.
            'tenant_id' => fn (array $attributes) => Customer::withoutGlobalScopes()->find($attributes['customer_id'])?->tenant_id,
            'name' => $this->faker->name(),
            'phone_number' => $this->faker->numerify('08##########'),
            'relationship' => $this->faker->randomElement(['Suami', 'Istri', 'Anak', 'Orang Tua', 'Saudara']),
            'access_level' => ContactAccessLevel::ViewOnly,
            'can_view_billing' => false,
            'can_request_service_change' => false,
            'can_receive_notifications' => true,
            'is_authorized_contact' => false,
        ];
    }

    public function authorized(): static
    {
        return $this->state(fn () => [
            'access_level' => ContactAccessLevel::Full,
            'can_view_billing' => true,
            'can_request_service_change' => true,
            'is_authorized_contact' => true,
        ]);
    }
}
