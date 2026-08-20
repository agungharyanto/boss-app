<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cid' => $this->cid,
            'name' => $this->name,
            'address' => $this->address,
            'phone_number' => $this->phone_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'reseller_id' => $this->reseller_id,
            'reseller_name' => $this->whenLoaded('reseller', fn () => $this->reseller?->name),
            'authorized_contact' => new CustomerContactResource($this->whenLoaded('authorizedContact')),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
