<?php

namespace App\Http\Resources;

use App\Enums\OdpPortStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OdpResource extends JsonResource
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
            'code' => $this->code,
            'name' => $this->name,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'total_ports' => $this->total_ports,
            'available_ports_count' => $this->whenLoaded('ports', fn () => $this->ports->where('status', OdpPortStatus::Available)->count()),
            'notes' => $this->notes,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
