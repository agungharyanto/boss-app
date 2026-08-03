<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            // Must match the parent invoice's tenant, not an independent random tenant.
            'tenant_id' => fn (array $attributes) => Invoice::withoutGlobalScopes()->find($attributes['invoice_id'])?->tenant_id,
            'xendit_reference_id' => 'xnd_'.$this->faker->uuid(),
            // Plain catalog code (payment_gateway_channels.code), not tied
            // to an existing row — no hard FK constraint (v0.3.5 Fase H).
            'channel_type' => 'BRI_VA',
            'amount' => $this->faker->randomFloat(2, 100000, 1000000),
            'status' => PaymentStatus::Pending,
            'paid_at' => null,
            'raw_response' => ['id' => 'xnd_test', 'status' => 'PENDING'],
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => PaymentStatus::Paid, 'paid_at' => now()]);
    }
}
