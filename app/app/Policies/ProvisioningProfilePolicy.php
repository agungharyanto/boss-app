<?php

namespace App\Policies;

use App\Models\User;

/**
 * v0.23.8.6b — tier-admin-only (pola sama PppPackagePolicy/
 * HotspotPackagePolicy): profil provisioning ZTP tak punya reseller_id
 * sendiri, jadi tak ada carve-out reseller_users. Tenant isolation
 * ditegakkan TenantScope (BelongsToTenant) + route-model-binding.
 */
class ProvisioningProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('provisioning_profiles.view') || $user->can('provisioning_profiles.manage');
    }

    public function view(User $user): bool
    {
        return $user->can('provisioning_profiles.view') || $user->can('provisioning_profiles.manage');
    }

    public function manage(User $user): bool
    {
        return $user->can('provisioning_profiles.manage');
    }
}
