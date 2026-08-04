<?php

namespace App\Services\Network;

use App\Enums\NasStatus;
use App\Exceptions\NasNotProvisionedException;
use App\Models\Nas;
use App\Services\Network\Contracts\RouterOsGateway;

class NasService
{
    public function __construct(
        private readonly RouterOsGateway $gateway,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $tenantId, ?int $resellerId): Nas
    {
        return Nas::create([
            ...$data,
            'tenant_id' => $tenantId,
            'reseller_id' => $resellerId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Nas $nas, array $data): Nas
    {
        $nas->update($data);

        return $nas->fresh();
    }

    public function delete(Nas $nas): void
    {
        $nas->delete();
    }

    /**
     * Pings the NAS's Mikrotik API (not RADIUS/ICMP) via RouterOsGateway,
     * then persists the observed status + last_ping_at regardless of
     * outcome — a failed ping is a legitimate, expected result (status
     * "offline"), not an error to swallow silently.
     *
     * mikrotik_ip is nullable pre-VPN-provisioning (v0.6.1 reality, filled
     * in starting v0.6.2) — attempting to connect with no IP at all is
     * refused up front with a clear message instead of a confusing socket
     * error.
     */
    public function testConnection(Nas $nas): Nas
    {
        if ($nas->mikrotik_ip === null) {
            throw new NasNotProvisionedException(
                "NAS '{$nas->name}' belum punya IP Mikrotik — belum di-provisioning lewat VPN (menyusul di v0.6.2)."
            );
        }

        $result = $this->gateway->ping($nas);

        $nas->update([
            'status' => $result['online'] ? NasStatus::Online : NasStatus::Offline,
            'last_ping_at' => now(),
        ]);

        return $nas->fresh();
    }
}
