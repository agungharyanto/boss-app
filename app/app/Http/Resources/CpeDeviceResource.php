<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CpeDeviceResource extends JsonResource
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
            'genieacs_device_id' => $this->genieacs_device_id,
            'manufacturer' => $this->manufacturer,
            'model_name' => $this->model_name,
            'serial_number' => $this->serial_number,
            'tr069_root' => $this->tr069_root?->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'last_inform_at' => $this->last_inform_at?->toIso8601String(),
            'bound_at' => $this->bound_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
