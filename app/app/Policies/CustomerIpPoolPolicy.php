<?php

namespace App\Policies;

use App\Models\User;

/**
 * v0.14.2 — tier-admin-only (superadmin/administrator), same shape and
 * same reasoning as BandwidthProfilePolicy (v0.14.1): CustomerIpPool has
 * no reseller_id column of its own (tenant-level only this sub-version,
 * per the sprint brief) — access control is NOT derived transitively
 * through the owning NAS's own reseller_id here, deliberately matching the
 * sibling sub-version's simpler posture rather than NasPolicy/
 * OltDevicePolicy's reseller_users carve-out.
 */
class CustomerIpPoolPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('customer_ip_pools.view') || $user->can('customer_ip_pools.manage');
    }

    public function view(User $user): bool
    {
        return $user->can('customer_ip_pools.view') || $user->can('customer_ip_pools.manage');
    }

    public function manage(User $user): bool
    {
        return $user->can('customer_ip_pools.manage');
    }
}
