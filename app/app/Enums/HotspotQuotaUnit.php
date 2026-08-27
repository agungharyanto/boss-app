<?php

namespace App\Enums;

/**
 * v0.14.4 amendment — "Satuan Data" for HotspotPackage::quota_value, only
 * meaningful when limit_type=QuotaBase. See the migration's own docblock
 * for why this is stored but never pushed to RouterOS this sub-version —
 * quota only ever applies per-USER (`/ip hotspot user`'s own
 * limit-bytes-total), not at the profile/package-template level.
 */
enum HotspotQuotaUnit: string
{
    case Mb = 'mb';
    case Gb = 'gb';

    public function label(): string
    {
        return match ($this) {
            self::Mb => 'MB',
            self::Gb => 'GB',
        };
    }
}
