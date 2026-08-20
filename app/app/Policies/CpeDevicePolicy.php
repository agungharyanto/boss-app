<?php

namespace App\Policies;

use App\Enums\ResellerUserStatus;
use App\Models\CpeDevice;
use App\Models\Reseller;
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

    /**
     * For a not-yet-existing CpeDevice — used by the "Tambah Device CPE"
     * form on a customer's own detail page (v0.7.6-follow-up), the first
     * manual bind path that doesn't go through a WorkOrder or the legacy
     * importer. Pass the target customer's own reseller via
     * $this->authorize('create', [CpeDevice::class, $customer->reseller]),
     * same pattern as OdpPolicy::create()/NasPolicy::create() — $reseller
     * null means "a direct/no-reseller customer", admin-only.
     */
    public function create(User $user, ?Reseller $reseller = null): bool
    {
        if ($user->can('cpe_devices.manage')) {
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
