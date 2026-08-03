<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResellerTaxPolicyResource extends JsonResource
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
            'burden' => $this->burden->value,
            'burden_label' => $this->burden->label(),
            'split_ratio' => $this->split_ratio !== null ? (float) $this->split_ratio : null,
            'is_active' => $this->is_active,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
