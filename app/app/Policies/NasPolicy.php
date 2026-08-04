<?php

namespace App\Policies;

use App\Enums\ResellerUserStatus;
use App\Models\Nas;
use App\Models\Reseller;
use App\Models\ResellerUser;
use App\Models\User;

/**
 * Same shape as OdpPolicy/TechnicianPolicy: admin (nas.view/.manage) gets
 * full access including ISP-direct NAS; a reseller's own NAS is
 * viewable/manageable by any active reseller_users membership.
 */
class NasPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('nas.view') || $user->can('nas.manage') || $this->belongsToAnyReseller($user);
    }

    public function view(User $user, Nas $nas): bool
    {
        if ($user->can('nas.view') || $user->can('nas.manage')) {
            return true;
        }

        return $nas->reseller_id !== null && $this->belongsToReseller($user, $nas->reseller_id);
    }

    public function manage(User $user, Nas $nas): bool
    {
        if ($user->can('nas.manage')) {
            return true;
        }

        return $nas->reseller_id !== null && $this->belongsToReseller($user, $nas->reseller_id);
    }

    public function create(User $user, ?Reseller $reseller = null): bool
    {
        if ($user->can('nas.manage')) {
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
