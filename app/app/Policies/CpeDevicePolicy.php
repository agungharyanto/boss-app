<?php

namespace App\Policies;

use App\Enums\ResellerUserStatus;
use App\Models\CpeDevice;
use App\Models\ResellerUser;
use App\Models\User;

/**
 * Same shape as OdpPolicy — admin (cpe_devices.view permission) sees
 * everything including direct/no-reseller devices; a reseller-owned device
 * is viewable by any active reseller_users membership (owner OR staff).
 * Read-only this sprint — no manage() at all, binding is fully automatic
 * (CpeBindingService), no manual create/edit action exists yet.
 */
class CpeDevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('cpe_devices.view') || $this->belongsToAnyReseller($user);
    }

    public function view(User $user, CpeDevice $cpeDevice): bool
    {
        if ($user->can('cpe_devices.view')) {
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
