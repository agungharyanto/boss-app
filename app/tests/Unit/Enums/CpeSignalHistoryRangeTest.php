<?php

namespace Tests\Unit\Enums;

use App\Enums\CpeSignalHistoryRange;
use Tests\TestCase;

/**
 * v0.8.3 — Custom Date Range tab (CLAUDE.md). Pins down the exact
 * boundary values for aggregationGrainForDays(), since the sprint brief's
 * tiers are stated as inclusive ("Range ≤ 1 hari", "≤ 7 hari", "≤ 31
 * hari") — an off-by-one here would silently aggregate a custom range
 * differently from the named tab of the same length
 * (CpeSignalHistoryQueryServiceTest covers the actual SQL behavior at a
 * few representative lengths; this file is purely about the boundary
 * values themselves).
 */
class CpeSignalHistoryRangeTest extends TestCase
{
    public function test_exactly_one_day_is_raw(): void
    {
        $this->assertNull(CpeSignalHistoryRange::aggregationGrainForDays(1.0));
    }

    public function test_just_over_one_day_is_hourly(): void
    {
        $this->assertSame('hour', CpeSignalHistoryRange::aggregationGrainForDays(1.01));
    }

    public function test_exactly_seven_days_is_hourly(): void
    {
        $this->assertSame('hour', CpeSignalHistoryRange::aggregationGrainForDays(7.0));
    }

    public function test_just_over_seven_days_is_daily(): void
    {
        $this->assertSame('day', CpeSignalHistoryRange::aggregationGrainForDays(7.01));
    }

    public function test_exactly_thirty_one_days_is_daily(): void
    {
        $this->assertSame('day', CpeSignalHistoryRange::aggregationGrainForDays(31.0));
    }

    public function test_just_over_thirty_one_days_is_weekly(): void
    {
        $this->assertSame('week', CpeSignalHistoryRange::aggregationGrainForDays(31.01));
    }

    public function test_two_years_is_still_weekly(): void
    {
        $this->assertSame('week', CpeSignalHistoryRange::aggregationGrainForDays(730.0));
    }
}
