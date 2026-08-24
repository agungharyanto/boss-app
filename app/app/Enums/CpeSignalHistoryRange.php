<?php

namespace App\Enums;

/**
 * v0.8.3 — the 5 range tabs on CpeSignalHistoryGraph. Window/aggregation
 * pairing is a locked decision (not derived), see CLAUDE.md's "RX Power
 * History (v0.8.3)" for the full table. Hour/Day stay raw (every ~20-minute
 * SyncCpeSignalHistory poll, unaggregated) — Week/Month/Year aggregate at
 * the SQL level (App\Services\Network\CpeSignalHistoryQueryService) so a
 * 365-day view never pulls ~26,000 raw rows just to average them in PHP.
 */
enum CpeSignalHistoryRange: string
{
    case Hour = 'hour';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    public function label(): string
    {
        return match ($this) {
            self::Hour => 'Jam',
            self::Day => 'Hari',
            self::Week => 'Minggu',
            self::Month => 'Bulan',
            self::Year => 'Tahun',
        };
    }

    public function windowHours(): int
    {
        return match ($this) {
            self::Hour => 3,
            self::Day => 24,
            self::Week => 24 * 7,
            self::Month => 24 * 30,
            self::Year => 24 * 365,
        };
    }

    /**
     * null = raw rows, no aggregation. Otherwise the SQL-level bucket grain
     * CpeSignalHistoryQueryService groups by.
     */
    public function aggregationGrain(): ?string
    {
        return match ($this) {
            self::Hour, self::Day => null,
            self::Week => 'hour',
            self::Month => 'day',
            self::Year => 'week',
        };
    }

    public static function default(): self
    {
        return self::Day;
    }

    /**
     * v0.8.4 — the external REST API's own `?range=` vocabulary
     * (hourly/daily/weekly/monthly/yearly) is deliberately spelled out in
     * full rather than reusing this enum's own short internal values
     * (`hour`/`day`/...) directly — decouples the public API contract from
     * this enum's internal naming, so a future rename of the internal
     * value string (unlikely, but not impossible) never breaks API
     * consumers. Reused as-is for BOTH `GET /cpe-devices/{id}/signal-
     * history` and `GET /monitoring/devices/{id}/traffic` — the
     * hourly/daily/weekly/monthly/yearly window concept isn't actually
     * CPE-specific despite this enum's name (a historical accident of
     * where it was first introduced, v0.8.3's RX Power history), so this
     * is a deliberate cross-purpose reuse for a shared external vocabulary
     * rather than two API endpoints inventing their own separate range
     * words.
     *
     * @throws \InvalidArgumentException on anything outside the 5 known words
     */
    public static function fromApiParam(string $param): self
    {
        return match ($param) {
            'hourly' => self::Hour,
            'daily' => self::Day,
            'weekly' => self::Week,
            'monthly' => self::Month,
            'yearly' => self::Year,
            default => throw new \InvalidArgumentException(
                "Range tidak dikenal: \"{$param}\". Gunakan salah satu: hourly, daily, weekly, monthly, yearly."
            ),
        };
    }

    /**
     * v0.8.3 — Custom Date Range tab (CLAUDE.md). Unlike aggregationGrain()
     * above (a fixed lookup per NAMED tab, e.g. "Minggu" is always
     * hour-grain regardless of the real 7-day window it represents), a
     * custom "Dari ... Sampai ..." range's grain must be derived from the
     * ACTUAL length the admin picked — the sprint brief's own explicit
     * requirement — not from a tab label that doesn't exist for this case.
     * Deliberately the SAME four tiers as the named tabs (≤1 day raw, ≤7d
     * hourly, ≤31d daily, else weekly) so a custom range that happens to
     * match a named range's length aggregates identically to that named
     * tab — no surprising discontinuity at the boundary between "pick
     * Weekly" and "pick Custom, 7 days".
     */
    public static function aggregationGrainForDays(float $days): ?string
    {
        return match (true) {
            $days <= 1.0 => null,
            $days <= 7.0 => 'hour',
            $days <= 31.0 => 'day',
            default => 'week',
        };
    }
}
