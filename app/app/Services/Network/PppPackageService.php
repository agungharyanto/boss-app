<?php

namespace App\Services\Network;

use App\Jobs\PushPppPackageToMikrotikJob;
use App\Jobs\RemovePppPackageFromMikrotikJob;
use App\Models\PppPackage;

/**
 * v0.14.5 — PppPackage (Profil PPP) business logic per BOSS-006. RouterOS
 * live-push follows the exact async Job pattern already established by
 * CustomerIpPoolService (v0.14.2.1)/NetworkProfileGroupService (v0.14.3)/
 * HotspotPackageService (v0.14.4) — reused, not reinvented. The cross-table
 * name-collision check (against both other PppPackage rows AND
 * NetworkProfileGroup rows on the same NAS) happens BEFORE this service is
 * ever called — in Store/UpdatePppPackageRequest's own withValidator() and
 * PppPackageIndex's own mirrored check — this service assumes already-
 * validated data, same as every other *Service class in this codebase.
 */
class PppPackageService
{
    /**
     * tenant_id is auto-filled by BelongsToTenant's creating() hook.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PppPackage
    {
        $package = PppPackage::create($data);
        $package->refresh();

        PushPppPackageToMikrotikJob::dispatch($package->id);

        return $package;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PppPackage $package, array $data): PppPackage
    {
        $package->update($data);
        $package->markSyncPending();

        PushPppPackageToMikrotikJob::dispatch($package->id);

        return $package->refresh();
    }

    public function delete(PppPackage $package): void
    {
        $package->delete();

        RemovePppPackageFromMikrotikJob::dispatch($package->id);
    }

    public function resync(PppPackage $package): void
    {
        $package->markSyncPending();

        PushPppPackageToMikrotikJob::dispatch($package->id);
    }
}
