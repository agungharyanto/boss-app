<?php

namespace App\Enums;

/**
 * v0.14.4 — Profil Hotspot "Masa Aktif" (active_duration_value/unit).
 * Backing values stay English/stable (this codebase's own established
 * convention — see CustomerIpPoolUsageType/NetworkProfileGroupType), label()
 * carries the Indonesian display text.
 *
 * RouterOS has no native "month" time-interval unit (only s/m/h/d/w) —
 * confirmed by reading `/ip hotspot user profile`'s real session-timeout
 * field, which only ever accepted plain m/h/d suffixes in a live test.
 * toDays() documents the Bulan->hari approximation (30 days/month) used
 * when converting to a RouterOS session-timeout string — see
 * HotspotPackage::routerOsSessionTimeout().
 *
 * Reused verbatim by v0.14.5 (Profil PPP, PppPackage::routerOsSessionTimeout())
 * — deliberately NOT duplicated into a separate PppDurationUnit, since the
 * conversion logic itself (m/h/d suffixes, no native month, 30-day
 * approximation) is identical and was already empirically verified once
 * here; re-confirmed via a fresh live test that `/ppp profile`'s own
 * session-timeout field accepts the exact same suffixes.
 */
enum HotspotDurationUnit: string
{
    case Minute = 'minute';
    case Hour = 'hour';
    case Day = 'day';
    case Month = 'month';

    public function label(): string
    {
        return match ($this) {
            self::Minute => 'Menit',
            self::Hour => 'Jam',
            self::Day => 'Hari',
            self::Month => 'Bulan',
        };
    }

    /**
     * RouterOS time-interval suffix for this unit — Month has no native
     * RouterOS equivalent, approximated as 30 days (documented, not exact
     * calendar-month arithmetic).
     */
    public function routerOsSuffix(): string
    {
        return match ($this) {
            self::Minute => 'm',
            self::Hour => 'h',
            self::Day, self::Month => 'd',
        };
    }

    /**
     * $value scaled into whatever routerOsSuffix() expects — only Month
     * needs actual scaling (×30, approximated), every other unit maps
     * 1:1 onto its own RouterOS suffix.
     */
    public function routerOsValue(int $value): int
    {
        return $this === self::Month ? $value * 30 : $value;
    }
}
