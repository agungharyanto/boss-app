<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KomdigiRemittanceSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'reseller_id' => $this->reseller_id,
            'reseller_name' => $this->whenLoaded('reseller', fn () => $this->reseller?->name),
            'tax_component_id' => $this->tax_component_id,
            'tax_component_code' => $this->whenLoaded('taxComponent', fn () => $this->taxComponent?->code),
            'total_base_amount' => (float) $this->total_base_amount,
            'total_tax_amount' => (float) $this->total_tax_amount,
            'total_customer_borne' => (float) $this->total_customer_borne,
            'total_reseller_borne' => (float) $this->total_reseller_borne,
            'transaction_count' => $this->transaction_count,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'generated_at' => $this->generated_at?->toIso8601String(),
            'remitted_at' => $this->remitted_at?->toIso8601String(),
        ];
    }
}
