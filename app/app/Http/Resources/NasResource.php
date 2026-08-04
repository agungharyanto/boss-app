<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NasResource extends JsonResource
{
    /**
     * api_password/radius_secret are NEVER included here — same posture as
     * PaymentGatewaySettings never re-rendering a saved secret. Callers only
     * ever get to know a NAS's radius_secret is *set*, not its value.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reseller_id' => $this->reseller_id,
            'reseller_name' => $this->whenLoaded('reseller', fn () => $this->reseller?->name),
            'name' => $this->name,
            'description' => $this->description,
            'mikrotik_ip' => $this->mikrotik_ip,
            'api_port' => $this->api_port,
            'api_username' => $this->api_username,
            'has_api_password' => $this->api_password !== null,
            'has_radius_secret' => $this->radius_secret !== null,
            'auth_port' => $this->auth_port,
            'acct_port' => $this->acct_port,
            'coa_port' => $this->coa_port,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'last_ping_at' => $this->last_ping_at?->toIso8601String(),
            'timezone' => $this->timezone,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
