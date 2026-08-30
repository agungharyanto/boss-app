<?php

namespace App\Policies;

use App\Models\User;

/**
 * v0.14.5 — tier-admin-only, same shape and reasoning as
 * HotspotPackagePolicy/NetworkProfileGroupPolicy: no reseller_id column of
 * its own this sub-version (visible_to_reseller is a plain display-
 * visibility boolean, not a reseller_users-membership-based authorization
 * carve-out).
 */
class PppPackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ppp_packages.view') || $user->can('ppp_packages.manage');
    }

    public function view(User $user): bool
    {
        return $user->can('ppp_packages.view') || $user->can('ppp_packages.manage');
    }

    public function manage(User $user): bool
    {
        return $user->can('ppp_packages.manage');
    }
}
