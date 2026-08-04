<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VpnAccountResource extends JsonResource
{
    /**
     * password is never included (same posture as NasResource — used by
     * L2TP/IPsec, v0.6.3, PPP-layer auth). public_key IS included — a
     * WireGuard public key is not secret. wireguard_private_key is included
     * ONLY the one time it's present on the model right after a fresh
     * provision() call (a transient, non-persisted PHP property, see
     * VpnAccount::$wireguardPrivateKey) — a revoke or any subsequent fetch
     * of this same account will never have it again, matching the "shown
     * once" posture already established for OpenVPN's exported config.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nas_id' => $this->nas_id,
            'vpn_server_id' => $this->vpn_server_id,
            'protocol' => $this->protocol->value,
            'protocol_label' => $this->protocol->label(),
            'username' => $this->username,
            'internal_ip' => $this->internal_ip,
            'cert_serial' => $this->cert_serial,
            'public_key' => $this->public_key,
            'wireguard_private_key' => $this->wireguardPrivateKey,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'connected_at' => $this->connected_at?->toIso8601String(),
        ];
    }
}
