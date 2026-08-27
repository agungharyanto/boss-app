<?php

namespace App\Enums;

/**
 * v0.14.4 — Profil Hotspot. Unlimited never carries a duration/limit_type
 * at all; Limited requires limit_type (see HotspotLimitType) plus an
 * active_duration_value/unit pair.
 */
enum HotspotProfileType: string
{
    case Unlimited = 'unlimited';
    case Limited = 'limited';

    public function label(): string
    {
        return match ($this) {
            self::Unlimited => 'Unlimited',
            self::Limited => 'Limited',
        };
    }
}
