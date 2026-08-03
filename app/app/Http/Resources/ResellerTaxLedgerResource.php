<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResellerTaxLedgerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reseller_id' => $this->reseller_id,
            'reseller_name' => $this->whenLoaded('reseller', fn () => $this->reseller?->name),
            'tax_component_id' => $this->tax_component_id,
            'tax_component_code' => $this->whenLoaded('taxComponent', fn () => $this->taxComponent?->code),
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'base_amount' => (float) $this->base_amount,
            'rate_applied' => (float) $this->rate_applied,
            'tax_amount' => (float) $this->tax_amount,
            'burden_applied' => $this->burden_applied->value,
            'customer_borne_amount' => $this->customer_borne_amount !== null ? (float) $this->customer_borne_amount : null,
            'reseller_borne_amount' => $this->reseller_borne_amount !== null ? (float) $this->reseller_borne_amount : null,
            'transaction_date' => $this->transaction_date->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'source' => $this->source->value,
            'notes' => $this->notes,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
