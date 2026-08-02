<?php

namespace App\Services;

use App\Enums\CommissionStatus;
use App\Enums\CustomerStatus;
use App\Enums\RegistrationChannel;
use App\Enums\RegistrationStatus;
use App\Models\Agent;
use App\Models\CommissionLedger;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

class RegistrationService
{
    /**
     * Registers a new customer, optionally attributed to a referring agent.
     *
     * When $registeredBy is given, the customer's registration_channel matches
     * that agent's own type (sales/teknisi/freelance), and a pending
     * commission_ledger row is created for them. With no agent (an admin
     * registering with no referral picked), registration_channel falls back
     * to 'admin' and no commission_ledger row is created at all.
     *
     * @param  array{name: string, address: string, phone_number: string, nik?: ?string, latitude?: ?float, longitude?: ?float, package?: ?string}  $data
     */
    public function register(array $data, ?Agent $registeredBy = null): Customer
    {
        return DB::transaction(function () use ($data, $registeredBy) {
            $customer = Customer::create([
                ...$data,
                'status' => CustomerStatus::Prospek,
                'registration_status' => RegistrationStatus::Registered,
                'registration_channel' => $registeredBy
                    ? RegistrationChannel::from($registeredBy->type->value)
                    : RegistrationChannel::Admin,
                'referred_by_agent_id' => $registeredBy?->id,
            ]);

            if ($registeredBy !== null) {
                CommissionLedger::create([
                    'tenant_id' => $customer->tenant_id,
                    'agent_id' => $registeredBy->id,
                    'customer_id' => $customer->id,
                    'status' => CommissionStatus::Pending,
                ]);
            }

            return $customer;
        });
    }
}
