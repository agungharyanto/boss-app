<?php

namespace App\Policies;

use App\Enums\ResellerUserStatus;
use App\Models\CpeDevice;
use App\Models\ResellerUser;
use App\Models\User;

/**
 * Same shape as OdpPolicy — admin (cpe_devices.view/.manage permission) sees
 * everything including direct/no-reseller devices; a reseller-owned device
 * is viewable by any active reseller_users membership (owner OR staff).
 *
 * v0.7.4 adds manage() (remote actions: reboot, WiFi credential change) —
 * binding itself is still fully automatic (CpeBindingService), there's still
 * no manual create/edit of the device ROW, but acting ON an already-bound
 * device now has a real permission gate, same reseller-ownership carve-out
 * as OdpPolicy::manage()/view().
 */
class CpeDevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('cpe_devices.view') || $user->can('cpe_devices.manage') || $this->belongsToAnyReseller($user);
    }

    public function view(User $user, CpeDevice $cpeDevice): bool
    {
        if ($user->can('cpe_devices.view') || $user->can('cpe_devices.manage')) {
            return true;
        }

        return $cpeDevice->reseller_id !== null && $this->belongsToReseller($user, $cpeDevice->reseller_id);
    }

    public function manage(User $user, CpeDevice $cpeDevice): bool
    {
        if ($user->can('cpe_devices.manage')) {
            return true;
        }

        return $cpeDevice->reseller_id !== null && $this->belongsToReseller($user, $cpeDevice->reseller_id);
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
