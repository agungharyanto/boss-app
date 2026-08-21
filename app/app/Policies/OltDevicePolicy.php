<?php

namespace App\Policies;

use App\Enums\ResellerUserStatus;
use App\Models\OltDevice;
use App\Models\Reseller;
use App\Models\ResellerUser;
use App\Models\User;

/**
 * Same shape as NasPolicy/OdpPolicy/TechnicianPolicy: admin
 * (olt_devices.view/.manage) gets full access including ISP-direct OLTs;
 * a reseller's own OLT is viewable/manageable by any active
 * reseller_users membership.
 */
class OltDevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('olt_devices.view') || $user->can('olt_devices.manage') || $this->belongsToAnyReseller($user);
    }

    public function view(User $user, OltDevice $oltDevice): bool
    {
        if ($user->can('olt_devices.view') || $user->can('olt_devices.manage')) {
            return true;
        }

        return $oltDevice->reseller_id !== null && $this->belongsToReseller($user, $oltDevice->reseller_id);
    }

    public function manage(User $user, OltDevice $oltDevice): bool
    {
        if ($user->can('olt_devices.manage')) {
            return true;
        }

        return $oltDevice->reseller_id !== null && $this->belongsToReseller($user, $oltDevice->reseller_id);
    }

    public function create(User $user, ?Reseller $reseller = null): bool
    {
        if ($user->can('olt_devices.manage')) {
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
