<?php

namespace App\Policies;

use App\Enums\ResellerUserStatus;
use App\Models\Reseller;
use App\Models\ResellerUser;
use App\Models\Technician;
use App\Models\User;

/**
 * Same shape as OdpPolicy: admin (technicians.view/.manage) gets full
 * access including ISP-direct technicians; a reseller's own technician is
 * viewable/manageable by any active reseller_users membership.
 */
class TechnicianPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('technicians.view') || $user->can('technicians.manage') || $this->belongsToAnyReseller($user);
    }

    public function view(User $user, Technician $technician): bool
    {
        if ($user->can('technicians.view') || $user->can('technicians.manage')) {
            return true;
        }

        return $technician->reseller_id !== null && $this->belongsToReseller($user, $technician->reseller_id);
    }

    public function manage(User $user, Technician $technician): bool
    {
        if ($user->can('technicians.manage')) {
            return true;
        }

        return $technician->reseller_id !== null && $this->belongsToReseller($user, $technician->reseller_id);
    }

    public function create(User $user, ?Reseller $reseller = null): bool
    {
        if ($user->can('technicians.manage')) {
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
