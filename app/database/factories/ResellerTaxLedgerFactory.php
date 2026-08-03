<?php

namespace Database\Factories;

use App\Enums\TaxBurden;
use App\Enums\TaxLedgerSource;
use App\Enums\TaxLedgerStatus;
use App\Models\ResellerTaxLedger;
use App\Models\TaxComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResellerTaxLedger>
 */
class ResellerTaxLedgerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $baseAmount = $this->faker->randomFloat(2, 100000, 1000000);
        $rate = $this->faker->randomFloat(4, 1, 15);
        $taxAmount = round($baseAmount * $rate / 100, 2);

        return [
            'tax_component_id' => TaxComponent::factory(),
            // Must match the tax component's tenant, not an independent random tenant.
            'tenant_id' => fn (array $attributes) => TaxComponent::withoutGlobalScopes()->find($attributes['tax_component_id'])?->tenant_id,
            'reseller_id' => null,
            'reference_type' => null,
            'reference_id' => null,
            'base_amount' => $baseAmount,
            'rate_applied' => $rate,
            'tax_amount' => $taxAmount,
            'burden_applied' => TaxBurden::CustomerBorne,
            'customer_borne_amount' => $taxAmount,
            'reseller_borne_amount' => null,
            'transaction_date' => now()->toDateString(),
            'status' => TaxLedgerStatus::Pending,
            'source' => TaxLedgerSource::Seeded,
            'notes' => null,
        ];
    }

    public function voided(): static
    {
        return $this->state(fn () => ['status' => TaxLedgerStatus::Voided]);
    }

    public function remitted(): static
    {
        return $this->state(fn () => ['status' => TaxLedgerStatus::Remitted]);
    }
}
