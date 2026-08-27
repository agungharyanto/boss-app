<?php

namespace App\Enums;

/**
 * v0.14.4 — Profil Hotspot, only meaningful when profile_type=Limited.
 *
 * TimeBase maps to RouterOS `/ip hotspot user profile`'s real
 * `session-timeout` field (confirmed via a live add/read/remove round trip
 * against ro-hotspot.bajastu.id) — pushed at live-push time.
 *
 * QuotaBase has NO RouterOS profile-level equivalent — confirmed by reading
 * every real field `/ip hotspot user profile` actually returned on a live
 * router (idle-timeout/keepalive-timeout/status-autorefresh/shared-users/
 * add-mac-cookie/mac-cookie-timeout/address-list/transparent-proxy/
 * rate-limit/session-timeout — no byte-quota field anywhere). A real byte
 * quota only exists per-USER (`/ip hotspot user`'s own
 * limit-bytes-in/out/total), which requires an individual hotspot
 * user/voucher to exist — out of scope this sub-version (Profil Hotspot is
 * the package TEMPLATE only, voucher/user generation is a later sub-
 * version). Note: this sprint's own migration spec has no accompanying
 * quota-AMOUNT column (e.g. quota_mb) — limit_type=QuotaBase is stored as
 * a pure classification flag only, with nothing yet to push anywhere;
 * flagged in the sprint report rather than inventing an unrequested column.
 */
enum HotspotLimitType: string
{
    case TimeBase = 'time_base';
    case QuotaBase = 'quota_base';

    public function label(): string
    {
        return match ($this) {
            self::TimeBase => 'Time Base',
            self::QuotaBase => 'Quota Base',
        };
    }
}
