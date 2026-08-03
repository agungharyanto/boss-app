<?php

namespace Database\Factories;

use App\Enums\RemittanceStatus;
use App\Models\KomdigiRemittanceSummary;
use App\Models\TaxComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KomdigiRemittanceSummary>
 */
class KomdigiRemittanceSummaryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $baseAmount = $this->faker->randomFloat(2, 1000000, 10000000);
        $taxAmount = round($baseAmount * 0.11, 2);

        return [
            'tax_component_id' => TaxComponent::factory(),
            // Must match the tax component's tenant, not an independent random tenant.
            'tenant_id' => fn (array $attributes) => TaxComponent::withoutGlobalScopes()->find($attributes['tax_component_id'])?->tenant_id,
            'reseller_id' => null,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'total_base_amount' => $baseAmount,
            'total_tax_amount' => $taxAmount,
            'total_customer_borne' => $taxAmount,
            'total_reseller_borne' => 0,
            'transaction_count' => $this->faker->numberBetween(5, 30),
            'status' => RemittanceStatus::Draft,
            'generated_at' => null,
            'remitted_at' => null,
        ];
    }

    public function finalized(): static
    {
        return $this->state(fn () => ['status' => RemittanceStatus::Finalized, 'generated_at' => now()]);
    }
}
