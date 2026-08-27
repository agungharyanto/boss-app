<?php

namespace App\Policies;

use App\Models\User;

/**
 * v0.14.1 — tier-admin-only (superadmin/administrator), same shape as
 * ReferrerPolicy/CpeParameterMapPolicy: BandwidthProfile has no
 * reseller_id at all (deliberately tenant-level only this sub-version, per
 * the sprint brief), so there is no reseller_users membership carve-out
 * here the way NasPolicy/OdpPolicy have.
 */
class BandwidthProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('bandwidth_profiles.view') || $user->can('bandwidth_profiles.manage');
    }

    public function view(User $user): bool
    {
        return $user->can('bandwidth_profiles.view') || $user->can('bandwidth_profiles.manage');
    }

    public function manage(User $user): bool
    {
        return $user->can('bandwidth_profiles.manage');
    }
}
