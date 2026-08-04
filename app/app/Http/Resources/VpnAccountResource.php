<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VpnAccountResource extends JsonResource
{
    /**
     * password is never included (same posture as NasResource) — not that
     * OpenVPN accounts populate it anyway (cert-based auth), but the column
     * exists for the L2TP/IPsec protocol arriving in v0.6.3.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nas_id' => $this->nas_id,
            'vpn_server_id' => $this->vpn_server_id,
            'protocol' => $this->protocol,
            'username' => $this->username,
            'internal_ip' => $this->internal_ip,
            'cert_serial' => $this->cert_serial,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'connected_at' => $this->connected_at?->toIso8601String(),
        ];
    }
}
