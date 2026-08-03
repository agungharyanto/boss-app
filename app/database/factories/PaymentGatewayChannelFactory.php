<?php

namespace Database\Factories;

use App\Enums\PaymentGatewayChannelCategory;
use App\Models\PaymentGatewayChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentGatewayChannel>
 */
class PaymentGatewayChannelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->lexify('CH_????')),
            'label' => $this->faker->words(2, true),
            'category' => PaymentGatewayChannelCategory::BankTransferVa,
            'enabled' => false,
        ];
    }

    public function enabled(): static
    {
        return $this->state(fn () => ['enabled' => true]);
    }
}
