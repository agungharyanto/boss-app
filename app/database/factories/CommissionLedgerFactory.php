<?php

namespace Database\Factories;

use App\Enums\CommissionStatus;
use App\Models\Agent;
use App\Models\CommissionLedger;
use App\Models\Customer;
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
            'agent_id' => Agent::factory(),
            // Must match the agent's tenant, not an independent random tenant.
            'tenant_id' => fn (array $attributes) => Agent::withoutGlobalScopes()->find($attributes['agent_id'])?->tenant_id,
            'customer_id' => Customer::factory(),
            'amount' => null,
            'status' => CommissionStatus::Pending,
            'notes' => null,
        ];
    }
}
