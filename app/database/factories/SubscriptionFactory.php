<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
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
            // Default direct-retail (reseller_id null) — pass ->for($reseller)
            // and reseller_package_pricing_id explicitly for a reseller-attributed subscription.
            'reseller_id' => null,
            'reseller_package_pricing_id' => null,
            'name' => $this->faker->randomElement(['Paket 10 Mbps', 'Paket 20 Mbps', 'Paket 50 Mbps']),
            'monthly_amount' => $this->faker->randomElement([150000, 250000, 350000]),
            'status' => SubscriptionStatus::Active,
            'billing_cycle_day' => $this->faker->numberBetween(1, 28),
            'started_at' => now()->subMonths(2)->toDateString(),
            'cancelled_at' => null,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => SubscriptionStatus::Suspended]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()->toDateString()]);
    }
}
