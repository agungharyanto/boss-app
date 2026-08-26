<?php

namespace App\Policies;

use App\Models\User;

/**
 * Strictly tier-admin-only (v0.9.2), same shape as CpeParameterMapPolicy —
 * Referrer has no reseller_id (tenant-level only), so there is no
 * reseller_users membership carve-out here the way NasPolicy/OdpPolicy have.
 * Cross-tenant access is already structurally blocked one layer below this
 * Policy by Referrer's own BelongsToTenant global scope (route-model-binding
 * on a cross-tenant id 404s before this Policy is ever reached), same
 * pattern as every other tenant-scoped model in this codebase.
 */
class ReferrerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('referrers.view') || $user->can('referrers.manage');
    }

    public function view(User $user): bool
    {
        return $user->can('referrers.view') || $user->can('referrers.manage');
    }

    public function manage(User $user): bool
    {
        return $user->can('referrers.manage');
    }
}
