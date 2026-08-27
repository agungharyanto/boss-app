<?php

namespace App\Services\Network;

use App\Models\CustomerIpPool;
use InvalidArgumentException;

/**
 * v0.14.2 — CustomerIpPool business logic (overlap detection, CIDR
 * containment) lives here per BOSS-006, not in the Controller/Livewire
 * component. See CustomerIpPool's own docblock for why this is a
 * deliberately distinct concept from VpnIpPool (v0.8.1).
 */
class CustomerIpPoolService
{
    /**
     * tenant_id is auto-filled by BelongsToTenant's creating() hook from
     * the authenticated user — never passed explicitly, same pattern as
     * every other tenant-scoped create() in this codebase.
     *
     * @param  array{nas_id: int, name: string, network_address: string, gateway_ip: string, range_start: string, range_end: string, dns_primary?: ?string, dns_secondary?: ?string, is_active?: bool}  $data
     */
    public function create(array $data): CustomerIpPool
    {
        return CustomerIpPool::create($data);
    }

    /**
     * @param  array{nas_id?: int, name?: string, network_address?: string, gateway_ip?: string, range_start?: string, range_end?: string, dns_primary?: ?string, dns_secondary?: ?string, is_active?: bool}  $data
     */
    public function update(CustomerIpPool $pool, array $data): CustomerIpPool
    {
        $pool->update($data);

        return $pool->refresh();
    }

    /**
     * Soft delete only — a CustomerIpPool once selected as a Grup Profil's
     * "Modul IP Pool" (v0.14.3+) must never disappear from historical
     * records those rows still reference, same reasoning as
     * BandwidthProfileService::delete().
     */
    public function delete(CustomerIpPool $pool): void
    {
        $pool->delete();
    }

    /**
     * True if range_start/range_end (as plain IP strings, not yet a real
     * model) overlaps ANY other active-or-not, non-soft-deleted pool on the
     * same NAS — soft-deleted pools are excluded the same way the unique
     * name index is (a removed pool's IP range is free to be reallocated).
     * $ignoreId excludes the pool itself on an update.
     */
    public function overlapsExistingRange(int $nasId, string $rangeStart, string $rangeEnd, ?int $ignoreId = null): bool
    {
        $candidate = new CustomerIpPool(['range_start' => $rangeStart, 'range_end' => $rangeEnd]);

        return CustomerIpPool::query()
            ->where('nas_id', $nasId)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->get(['id', 'range_start', 'range_end'])
            ->contains(fn (CustomerIpPool $existing) => $candidate->overlapsRange($existing));
    }

    /**
     * True if $ip (a plain dotted-quad string, already validated as a real
     * IP by the caller) falls within $cidr's network..broadcast range
     * inclusive — deliberately loose ("validasi dasar, tidak perlu terlalu
     * ketat" per the sprint brief): does not exclude the network/broadcast
     * addresses themselves the way a strict "usable host" check would (see
     * App\Support\CidrRange::usableHostAddresses() for that stricter,
     * VPN-tunnel-specific version — not reused here on purpose, this is a
     * genuinely different, looser requirement).
     */
    public static function ipWithinCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);

        if (count($parts) !== 2) {
            throw new InvalidArgumentException("CIDR tidak valid: {$cidr}");
        }

        [$base, $prefix] = $parts;
        $prefix = (int) $prefix;

        if ($prefix < 0 || $prefix > 32 || ip2long($base) === false || ip2long($ip) === false) {
            return false;
        }

        $hostBits = 32 - $prefix;
        $networkLong = ip2long($base) & (-1 << $hostBits);
        $broadcastLong = $networkLong | ((1 << $hostBits) - 1);
        $ipLong = ip2long($ip);

        return $ipLong >= $networkLong && $ipLong <= $broadcastLong;
    }
}
