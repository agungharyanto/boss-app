<?php

namespace App\Policies;

use App\Models\User;

/**
 * v0.14.4 — tier-admin-only, same shape and reasoning as
 * NetworkProfileGroupPolicy/CustomerIpPoolPolicy/BandwidthProfilePolicy: no
 * reseller_id column of its own this sub-version (visible_to_reseller is a
 * plain display-visibility boolean, not a reseller_users-membership-based
 * authorization carve-out).
 */
class HotspotPackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('hotspot_packages.view') || $user->can('hotspot_packages.manage');
    }

    public function view(User $user): bool
    {
        return $user->can('hotspot_packages.view') || $user->can('hotspot_packages.manage');
    }

    public function manage(User $user): bool
    {
        return $user->can('hotspot_packages.manage');
    }
}
