<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A referred Customer, annotated with the commission_ledger row created for
 * it at registration time (see RegistrationService::register()).
 */
class ReferrerReferralResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $commission = $this->commissionLedgerEntries->first();

        return [
            'customer_id' => $this->id,
            'customer_name' => $this->name,
            'registration_status' => $this->registration_status->value,
            'registration_status_label' => $this->registration_status->label(),
            'commission_status' => $commission?->status->value,
            'commission_status_label' => $commission?->status->label(),
            'commission_amount' => $commission?->amount,
            'registered_at' => $this->created_at->toIso8601String(),
        ];
    }
}
