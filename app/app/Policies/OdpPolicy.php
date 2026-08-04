<?php

namespace App\Policies;

use App\Enums\ResellerUserStatus;
use App\Models\Odp;
use App\Models\Reseller;
use App\Models\ResellerUser;
use App\Models\User;

/**
 * Admin (odps.view/.manage permission) gets full access, including
 * direct/no-reseller ODPs. A reseller-owned ODP (reseller_id not null) is
 * viewable/manageable by any active reseller_users membership (owner OR
 * staff — same posture as the WhatsApp gateway module, not the
 * owner-only-write pattern from ResellerTaxPolicyPolicy).
 */
class OdpPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('odps.view') || $user->can('odps.manage') || $this->belongsToAnyReseller($user);
    }

    public function view(User $user, Odp $odp): bool
    {
        if ($user->can('odps.view') || $user->can('odps.manage')) {
            return true;
        }

        return $odp->reseller_id !== null && $this->belongsToReseller($user, $odp->reseller_id);
    }

    public function manage(User $user, Odp $odp): bool
    {
        if ($user->can('odps.manage')) {
            return true;
        }

        return $odp->reseller_id !== null && $this->belongsToReseller($user, $odp->reseller_id);
    }

    /**
     * For a not-yet-existing ODP — pass the intended reseller via
     * $this->authorize('create', [Odp::class, $reseller]), same pattern as
     * ResellerTaxPolicyPolicy::create(). $reseller null means "create a
     * direct/no-reseller ODP", admin-only.
     */
    public function create(User $user, ?Reseller $reseller = null): bool
    {
        if ($user->can('odps.manage')) {
            return true;
        }

        return $reseller !== null && $this->belongsToReseller($user, $reseller->id);
    }

    private function belongsToReseller(User $user, int $resellerId): bool
    {
        return ResellerUser::query()
            ->where('reseller_id', $resellerId)
            ->where('user_id', $user->id)
            ->where('status', ResellerUserStatus::Active)
            ->exists();
    }

    private function belongsToAnyReseller(User $user): bool
    {
        return ResellerUser::query()
            ->where('user_id', $user->id)
            ->where('status', ResellerUserStatus::Active)
            ->exists();
    }
}
