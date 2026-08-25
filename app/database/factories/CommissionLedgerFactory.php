<?php

namespace Database\Factories;

use App\Enums\CommissionStatus;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\Referrer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionLedger>
 */
class CommissionLedgerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'referrer_id' => Referrer::factory(),
            // Must match the referrer's tenant, not an independent random tenant.
            'tenant_id' => fn (array $attributes) => Referrer::withoutGlobalScopes()->find($attributes['referrer_id'])?->tenant_id,
            'customer_id' => Customer::factory(),
            'amount' => null,
            'status' => CommissionStatus::Pending,
            'notes' => null,
        ];
    }
}
