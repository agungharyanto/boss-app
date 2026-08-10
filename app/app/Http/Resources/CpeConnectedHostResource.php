<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CpeConnectedHostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mac_address' => $this->mac_address,
            'hostname' => $this->hostname,
            'ip_address' => $this->ip_address,
            'is_active' => $this->is_active,
            'first_seen_at' => $this->first_seen_at->toIso8601String(),
            'last_seen_at' => $this->last_seen_at->toIso8601String(),
        ];
    }
}
