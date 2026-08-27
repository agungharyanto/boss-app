<?php

namespace App\Services\Network;

use App\Jobs\PushHotspotPackageToMikrotikJob;
use App\Jobs\RemoveHotspotPackageFromMikrotikJob;
use App\Models\HotspotPackage;

/**
 * v0.14.4 — HotspotPackage (Profil Hotspot) business logic per BOSS-006.
 * RouterOS live-push follows the exact async Job pattern already
 * established by CustomerIpPoolService (v0.14.2.1)/NetworkProfileGroupService
 * (v0.14.3) — reused, not reinvented. Unlike NetworkProfileGroupService,
 * there is no separate FreeRADIUS radgroupreply write here — a Profil
 * Hotspot's own NetworkProfileGroup already owns that (see
 * NetworkProfileGroupService::writeRadiusGroupReply()); this service's
 * only side effect is the RouterOS push.
 */
class HotspotPackageService
{
    /**
     * tenant_id is auto-filled by BelongsToTenant's creating() hook.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): HotspotPackage
    {
        $package = HotspotPackage::create($data);
        $package->refresh();

        PushHotspotPackageToMikrotikJob::dispatch($package->id);

        return $package;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(HotspotPackage $package, array $data): HotspotPackage
    {
        $package->update($data);
        $package->markSyncPending();

        PushHotspotPackageToMikrotikJob::dispatch($package->id);

        return $package->refresh();
    }

    public function delete(HotspotPackage $package): void
    {
        $package->delete();

        RemoveHotspotPackageFromMikrotikJob::dispatch($package->id);
    }

    public function resync(HotspotPackage $package): void
    {
        $package->markSyncPending();

        PushHotspotPackageToMikrotikJob::dispatch($package->id);
    }
}
