<?php

namespace App\Services\Network;

use App\Models\BandwidthProfile;

class BandwidthProfileService
{
    /**
     * tenant_id is auto-filled by BelongsToTenant's creating() hook from
     * the authenticated user — never passed explicitly, same pattern as
     * every other tenant-scoped create() in this codebase (Referrer, etc).
     *
     * @param  array{name: string, upload_min: int, upload_max: int, download_min: int, download_max: int, is_active?: bool}  $data
     */
    public function create(array $data): BandwidthProfile
    {
        return BandwidthProfile::create($data);
    }

    /**
     * @param  array{name?: string, upload_min?: int, upload_max?: int, download_min?: int, download_max?: int, is_active?: bool}  $data
     */
    public function update(BandwidthProfile $profile, array $data): BandwidthProfile
    {
        $profile->update($data);

        return $profile->refresh();
    }

    /**
     * Soft delete only — a BandwidthProfile once linked to a Grup Profil/
     * Profil Hotspot/Profil PPP (v0.14.3+) must never disappear from
     * historical records those rows still reference.
     */
    public function delete(BandwidthProfile $profile): void
    {
        $profile->delete();
    }
}
