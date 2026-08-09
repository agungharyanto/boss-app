<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CpeParameterMapResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'oui' => $this->oui,
            'product_class' => $this->product_class,
            'parameter_key' => $this->parameter_key,
            'parameter_path' => $this->parameter_path,
            'value_type' => $this->value_type,
            'conversion_formula' => $this->conversion_formula->value,
            'conversion_params' => $this->conversion_params,
            'is_verified' => $this->isVerified(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'verified_against_device_id' => $this->verified_against_device_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
