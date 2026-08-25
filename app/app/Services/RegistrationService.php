<?php

namespace App\Services;

use App\Enums\CommissionStatus;
use App\Enums\CustomerStatus;
use App\Enums\RegistrationChannel;
use App\Enums\RegistrationStatus;
use App\Enums\WhatsappEventType;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\Referrer;
use App\Services\Whatsapp\WhatsappGatewayService;
use Illuminate\Support\Facades\DB;

class RegistrationService
{
    public function __construct(
        private readonly WhatsappGatewayService $whatsappService,
    ) {}

    /**
     * Registers a new customer, optionally attributed to a referring referrer.
     *
     * When $registeredBy is given, the customer's registration_channel matches
     * that referrer's own type (sales/teknisi/freelance), and a pending
     * commission_ledger row is created for them. With no referrer (an admin
     * registering with no referral picked), registration_channel falls back
     * to 'admin' and no commission_ledger row is created at all.
     *
     * @param  array{name: string, address: string, phone_number: string, nik?: ?string, latitude?: ?float, longitude?: ?float, package?: ?string}  $data
     */
    public function register(array $data, ?Referrer $registeredBy = null): Customer
    {
        $customer = DB::transaction(function () use ($data, $registeredBy) {
            $customer = Customer::create([
                ...$data,
                'status' => CustomerStatus::Prospek,
                'registration_status' => RegistrationStatus::Registered,
                'registration_channel' => $registeredBy
                    ? RegistrationChannel::from($registeredBy->type->value)
                    : RegistrationChannel::Admin,
                'referred_by_referrer_id' => $registeredBy?->id,
            ]);

            if ($registeredBy !== null) {
                CommissionLedger::create([
                    'tenant_id' => $customer->tenant_id,
                    'referrer_id' => $registeredBy->id,
                    'customer_id' => $customer->id,
                    'status' => CommissionStatus::Pending,
                ]);
            }

            return $customer;
        });

        // v0.4.0 WhatsApp Gateway hook — outside the transaction above so a
        // notification never fires for a registration that ends up rolled
        // back. This flow never sets customer.reseller_id (confirmed,
        // accepted as-is — see docs/ROADMAP.md), so session_key always
        // resolves to "direct" here.
        $this->whatsappService->buildAndQueue(WhatsappEventType::CustomerRegistered, $customer);

        return $customer;
    }
}
