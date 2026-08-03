<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'reseller_id' => $this->reseller_id,
            'reseller_name' => $this->whenLoaded('reseller', fn () => $this->reseller?->name),
            'reseller_package_pricing_id' => $this->reseller_package_pricing_id,
            'name' => $this->name,
            'monthly_amount' => (float) $this->monthly_amount,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'billing_cycle_day' => $this->billing_cycle_day,
            'started_at' => $this->started_at->toDateString(),
            'cancelled_at' => $this->cancelled_at?->toDateString(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
