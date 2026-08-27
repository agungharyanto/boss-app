<?php

namespace App\Policies;

use App\Models\User;

/**
 * v0.14.3 — tier-admin-only, same shape and reasoning as
 * CustomerIpPoolPolicy/BandwidthProfilePolicy: no reseller_id column of
 * its own this sub-version.
 */
class NetworkProfileGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('network_profile_groups.view') || $user->can('network_profile_groups.manage');
    }

    public function view(User $user): bool
    {
        return $user->can('network_profile_groups.view') || $user->can('network_profile_groups.manage');
    }

    public function manage(User $user): bool
    {
        return $user->can('network_profile_groups.manage');
    }
}
