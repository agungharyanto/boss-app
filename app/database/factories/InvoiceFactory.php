<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodStart = now()->startOfMonth();
        $subtotal = $this->faker->randomFloat(2, 100000, 1000000);
        $taxTotal = round($subtotal * 0.11, 2);

        return [
            'subscription_id' => Subscription::factory(),
            // Must match the parent subscription's tenant/customer/reseller,
            // not independently random ones.
            'tenant_id' => fn (array $attributes) => Subscription::withoutGlobalScopes()->find($attributes['subscription_id'])?->tenant_id,
            'customer_id' => fn (array $attributes) => Subscription::withoutGlobalScopes()->find($attributes['subscription_id'])?->customer_id,
            'reseller_id' => fn (array $attributes) => Subscription::withoutGlobalScopes()->find($attributes['subscription_id'])?->reseller_id,
            'invoice_number' => 'INV/TEST/'.$this->faker->unique()->numerify('######'),
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodStart->copy()->addMonth()->subDay()->toDateString(),
            'due_date' => $periodStart->copy()->addMonth()->subDay()->toDateString(),
            'status' => InvoiceStatus::Draft,
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'grand_total' => round($subtotal + $taxTotal, 2),
            'generated_at' => now(),
            'paid_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => InvoiceStatus::Pending]);
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => InvoiceStatus::Paid, 'paid_at' => now()]);
    }

    public function overdue(): static
    {
        return $this->state(fn () => ['status' => InvoiceStatus::Overdue, 'due_date' => now()->subDays(5)->toDateString()]);
    }
}
