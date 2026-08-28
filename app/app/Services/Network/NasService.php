<?php

namespace App\Services\Network;

use App\Enums\NasStatus;
use App\Exceptions\NasNotProvisionedException;
use App\Jobs\PushExpiredProfileToMikrotikJob;
use App\Jobs\RemoveExpiredProfileFromMikrotikJob;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Services\Network\Contracts\RouterOsGateway;
use InvalidArgumentException;

class NasService
{
    public function __construct(
        private readonly RouterOsGateway $gateway,
        private readonly NasPortAllocatorService $portAllocator,
        private readonly FreeradiusVirtualServerService $virtualServers,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $tenantId, ?int $resellerId): Nas
    {
        // v0.6.5 — no input path (StoreNasRequest, NasIndex Livewire form)
        // ever supplies auth_port/acct_port; every new NAS is allocated a
        // fresh, globally-unique pair automatically. See
        // NasPortAllocatorService's docblock for why this can't collide
        // even under concurrent NAS creation, and for why coa_port is
        // deliberately NOT part of this allocation (stays whatever $data
        // provides, or the plain DB default of 3799).
        $nas = Nas::create([
            ...$data,
            ...$this->portAllocator->allocate(),
            'tenant_id' => $tenantId,
            'reseller_id' => $resellerId,
        ]);

        $this->virtualServers->sync($nas);

        // ->fresh(), not the in-memory $nas — coa_port wasn't part of the
        // insert's own attribute array (it's DB-default-3799 unless $data
        // overrides it), and Eloquent doesn't back-fill column defaults
        // into the in-memory model after INSERT, only Postgres itself
        // knows the value until reloaded. Same reasoning as update()
        // returning ->fresh() below.
        return $nas->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Nas $nas, array $data): Nas
    {
        $nas->update($data);

        // radius_secret is the only field a virtual-server regeneration
        // actually cares about (auth_port/acct_port are immutable post-
        // allocation — nothing in this codebase ever writes to them again
        // after create()) — but re-syncing unconditionally on every update
        // is simplest and cheap (idempotent file write, only actually
        // triggers a radiusd restart if the written content changed, see
        // FreeradiusVirtualServerService::sync()'s docblock).
        $this->virtualServers->sync($nas);

        return $nas->fresh();
    }

    public function delete(Nas $nas): void
    {
        $this->virtualServers->remove($nas);

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

    /**
     * Revisi Grup Profil (Langkah 3) — sets/clears this NAS's own "Profile
     * Pelanggan Expired" fallback pool, then dispatches the matching
     * RouterOS live-push (Nas::expiredIpPool()'s own docblock explains why
     * this validation lives here rather than at the Eloquent relation
     * level). Same async-Job posture as CustomerIpPoolService/
     * NetworkProfileGroupService — never a synchronous router call from
     * this method itself.
     */
    public function updateExpiredIpPool(Nas $nas, ?int $expiredIpPoolId): Nas
    {
        if ($expiredIpPoolId !== null) {
            $pool = CustomerIpPool::find($expiredIpPoolId);

            if ($pool === null || $pool->nas_id !== $nas->id) {
                throw new InvalidArgumentException('IP Pool yang dipilih harus milik NAS yang sama.');
            }
        }

        $nas->update(['expired_ip_pool_id' => $expiredIpPoolId]);

        if ($expiredIpPoolId === null) {
            $nas->update([
                'expired_profile_mikrotik_sync_status' => null,
                'expired_profile_mikrotik_synced_at' => null,
                'expired_profile_mikrotik_sync_error' => null,
            ]);
            RemoveExpiredProfileFromMikrotikJob::dispatch($nas->id);

            return $nas->fresh();
        }

        $nas->markExpiredProfileSyncPending();
        PushExpiredProfileToMikrotikJob::dispatch($nas->id);

        return $nas->fresh();
    }
}
