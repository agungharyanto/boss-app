<?php

namespace Database\Factories;

use App\Enums\TaxBurden;
use App\Models\ResellerTaxPolicy;
use App\Models\TaxComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResellerTaxPolicy>
 */
class ResellerTaxPolicyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tax_component_id' => TaxComponent::factory(),
            // Must match the tax component's tenant, not an independent random tenant.
            'tenant_id' => fn (array $attributes) => TaxComponent::withoutGlobalScopes()->find($attributes['tax_component_id'])?->tenant_id,
            // Default direct-retail (reseller_id null) — pass ->for($reseller) to scope to one.
            'reseller_id' => null,
            'burden' => TaxBurden::CustomerBorne,
            'split_ratio' => null,
            'is_active' => true,
            'effective_from' => now()->startOfMonth()->toDateString(),
            'effective_to' => null,
            'set_by' => null,
        ];
    }

    public function split(float $ratio = 50.0): static
    {
        return $this->state(fn () => ['burden' => TaxBurden::Split, 'split_ratio' => $ratio]);
    }

    public function resellerBorne(): static
    {
        return $this->state(fn () => ['burden' => TaxBurden::ResellerBorne, 'split_ratio' => null]);
    }
}
