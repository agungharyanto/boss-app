<?php

namespace Database\Factories;

use App\Models\CommissionRate;
use App\Models\PppPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionRate>
 */
class CommissionRateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // ppp_package_id declared BEFORE the tenant_id closure — a
            // factory closure reading $attributes['ppp_package_id'] needs it
            // already resolved to a scalar (same ordering gotcha documented
            // for CustomerIpPoolFactory/NetworkProfileGroupFactory).
            'ppp_package_id' => PppPackage::factory(),
            'tenant_id' => fn (array $attributes) => PppPackage::withoutGlobalScopes()->find($attributes['ppp_package_id'])?->tenant_id,
            'recurring_amount' => 25000,
            'limited_count_amount' => null,
            'limited_count_times' => null,
            'titip_amount' => null,
            'is_active' => true,
        ];
    }
}
