<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerIpPoolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nas_id' => $this->nas_id,
            'nas_name' => $this->whenLoaded('nas', fn () => $this->nas->name),
            'name' => $this->name,
            'usage_type' => $this->usage_type?->value,
            'network_address' => $this->network_address,
            'gateway_ip' => $this->gateway_ip,
            'range_start' => $this->range_start,
            'range_end' => $this->range_end,
            'dns_primary' => $this->dns_primary,
            'dns_secondary' => $this->dns_secondary,
            'is_active' => $this->is_active,
            'mikrotik_sync_status' => $this->mikrotik_sync_status?->value,
            'mikrotik_synced_at' => $this->mikrotik_synced_at?->toIso8601String(),
            'mikrotik_sync_error' => $this->mikrotik_sync_error,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
