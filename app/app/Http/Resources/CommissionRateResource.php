<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ppp_package_id' => $this->ppp_package_id,
            'ppp_package' => $this->whenLoaded('pppPackage', fn () => [
                'id' => $this->pppPackage->id,
                'name' => $this->pppPackage->name,
                'sell_price' => $this->pppPackage->sell_price,
                'promo_price' => $this->pppPackage->promo_price,
            ]),
            'recurring_amount' => $this->recurring_amount,
            'limited_count_amount' => $this->limited_count_amount,
            'limited_count_times' => $this->limited_count_times,
            'titip_amount' => $this->titip_amount,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
