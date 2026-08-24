<?php

namespace Tests\Feature\Network;

use App\Enums\CpeSignalHistoryRange;
use App\Models\CpeDevice;
use App\Models\CpeSignalHistory;
use App\Models\Tenant;
use App\Services\Network\CpeSignalHistoryQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Runs against the real SQLite test connection (phpunit.xml) — the
 * bucket-boundary SQL itself (App\Services\Network\
 * CpeSignalHistoryQueryService::bucketExpression()) is exactly the kind of
 * thing that must be exercised against a real driver, not mocked; SQLite
 * and PostgreSQL are independently implemented per-driver branches (see
 * that method's own docblock), so this file only proves the SQLite side —
 * the PostgreSQL branch is the same shape, verified by code review + the
 * fact that `date_trunc` is PostgreSQL's own well-documented native
 * truncation function (no custom arithmetic to get wrong there, unlike the
 * hand-rolled SQLite week idiom this file exists to pin down).
 */
class CpeSignalHistoryQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function device(): CpeDevice
    {
        $tenant = Tenant::factory()->create();

        return CpeDevice::factory()->create(['tenant_id' => $tenant->id]);
    }

    private function point(CpeDevice $device, ?float $value, \DateTimeInterface $at): void
    {
        CpeSignalHistory::factory()->create([
            'cpe_device_id' => $device->id,
            'rx_power_dbm' => $value,
            'recorded_at' => $at,
        ]);
    }

    public function test_hour_and_day_ranges_return_raw_unaggregated_rows(): void
    {
        $device = $this->device();
        $this->point($device, -20.0, now()->subMinutes(40));
        $this->point($device, -21.0, now()->subMinutes(20));

        $service = app(CpeSignalHistoryQueryService::class);

        $this->assertCount(2, $service->seriesFor($device->id, CpeSignalHistoryRange::Hour));
        $this->assertCount(2, $service->seriesFor($device->id, CpeSignalHistoryRange::Day));
    }

    public function test_hour_range_excludes_points_older_than_3_hours(): void
    {
        $device = $this->device();
        $this->point($device, -20.0, now()->subHours(4));
        $this->point($device, -21.0, now()->subMinutes(30));

        $series = app(CpeSignalHistoryQueryService::class)->seriesFor($device->id, CpeSignalHistoryRange::Hour);

        $this->assertCount(1, $series);
        $this->assertEqualsWithDelta(-21.0, $series[0]['rx_power_dbm'], 0.01);
    }

    public function test_week_range_averages_into_hourly_buckets(): void
    {
        $device = $this->device();
        $base = now()->subDays(2)->startOfHour();

        // Two points in the SAME hour bucket -> averaged together.
        $this->point($device, -20.0, $base->copy()->addMinutes(5));
        $this->point($device, -22.0, $base->copy()->addMinutes(45));
        // One point a full hour later -> its own separate bucket.
        $this->point($device, -30.0, $base->copy()->addHour()->addMinutes(10));

        $series = app(CpeSignalHistoryQueryService::class)->seriesFor($device->id, CpeSignalHistoryRange::Week);

        $this->assertCount(2, $series);
        $this->assertEqualsWithDelta(-21.0, $series[0]['rx_power_dbm'], 0.01);
        $this->assertEqualsWithDelta(-30.0, $series[1]['rx_power_dbm'], 0.01);
    }

    public function test_month_range_averages_into_daily_buckets(): void
    {
        $device = $this->device();
        $day1 = now()->subDays(10)->startOfDay();

        $this->point($device, -18.0, $day1->copy()->addHours(2));
        $this->point($device, -22.0, $day1->copy()->addHours(20));
        $this->point($device, -40.0, $day1->copy()->addDay()->addHours(5));

        $series = app(CpeSignalHistoryQueryService::class)->seriesFor($device->id, CpeSignalHistoryRange::Month);

        $this->assertCount(2, $series);
        $this->assertEqualsWithDelta(-20.0, $series[0]['rx_power_dbm'], 0.01);
        $this->assertEqualsWithDelta(-40.0, $series[1]['rx_power_dbm'], 0.01);
    }

    public function test_year_range_averages_into_weekly_buckets_monday_aligned(): void
    {
        $device = $this->device();
        // A known Monday, far enough back to stay inside a 365-day window
        // regardless of when this test runs.
        $monday = now()->subDays(60)->startOfWeek(); // Carbon's startOfWeek defaults to Monday.

        $this->point($device, -19.0, $monday->copy()->addHours(3)); // Monday
        $this->point($device, -21.0, $monday->copy()->addDays(6)->addHours(1)); // Sunday, SAME week
        $this->point($device, -50.0, $monday->copy()->addDays(7)->addHours(1)); // next Monday, NEW week

        $series = app(CpeSignalHistoryQueryService::class)->seriesFor($device->id, CpeSignalHistoryRange::Year);

        $this->assertCount(2, $series);
        $this->assertEqualsWithDelta(-20.0, $series[0]['rx_power_dbm'], 0.01);
        $this->assertEqualsWithDelta(-50.0, $series[1]['rx_power_dbm'], 0.01);
    }

    /**
     * The bucket-boundary edge case that a naive single-modifier SQLite
     * expression gets wrong (see bucketExpression()'s own docblock) — a
     * point recorded EXACTLY on a Monday must bucket with itself, not roll
     * into the previous or next week.
     */
    public function test_week_bucket_boundary_is_correct_for_a_point_exactly_on_monday(): void
    {
        $device = $this->device();
        $monday = now()->subDays(60)->startOfWeek();

        $this->point($device, -19.0, $monday->copy()->addMinutes(1));
        $this->point($device, -50.0, $monday->copy()->subMinutes(1)); // still Sunday, previous week

        $series = app(CpeSignalHistoryQueryService::class)->seriesFor($device->id, CpeSignalHistoryRange::Year);

        $this->assertCount(2, $series);
        $this->assertEqualsWithDelta(-50.0, $series[0]['rx_power_dbm'], 0.01);
        $this->assertEqualsWithDelta(-19.0, $series[1]['rx_power_dbm'], 0.01);
    }

    public function test_aggregated_bucket_with_only_null_rows_stays_null_not_zero(): void
    {
        $device = $this->device();
        $base = now()->subDays(2)->startOfHour();

        $this->point($device, null, $base->copy()->addMinutes(5));
        $this->point($device, null, $base->copy()->addMinutes(45));

        $series = app(CpeSignalHistoryQueryService::class)->seriesFor($device->id, CpeSignalHistoryRange::Week);

        $this->assertCount(1, $series);
        $this->assertNull($series[0]['rx_power_dbm']);
    }

    public function test_aggregated_bucket_ignores_nulls_when_averaging_real_values(): void
    {
        $device = $this->device();
        $base = now()->subDays(2)->startOfHour();

        $this->point($device, -20.0, $base->copy()->addMinutes(5));
        $this->point($device, null, $base->copy()->addMinutes(25));
        $this->point($device, -24.0, $base->copy()->addMinutes(45));

        $series = app(CpeSignalHistoryQueryService::class)->seriesFor($device->id, CpeSignalHistoryRange::Week);

        $this->assertCount(1, $series);
        // AVG() ignores the NULL row entirely — (-20 + -24) / 2, not / 3.
        $this->assertEqualsWithDelta(-22.0, $series[0]['rx_power_dbm'], 0.01);
    }

    public function test_no_data_at_all_returns_empty_series_for_every_range(): void
    {
        $device = $this->device();
        $service = app(CpeSignalHistoryQueryService::class);

        foreach (CpeSignalHistoryRange::cases() as $range) {
            $this->assertSame([], $service->seriesFor($device->id, $range));
        }
    }

    // v0.8.3 — Custom Date Range tab, see CLAUDE.md's own section.

    public function test_custom_range_of_half_a_day_returns_raw_rows(): void
    {
        $device = $this->device();
        $from = now()->subMonths(3)->startOfDay();
        $to = $from->copy()->addHours(12);

        $this->point($device, -20.0, $from->copy()->addHours(2));
        $this->point($device, -21.0, $from->copy()->addHours(6));
        // Outside the window entirely — must never appear.
        $this->point($device, -99.0, $from->copy()->subDay());

        $series = app(CpeSignalHistoryQueryService::class)->customSeriesFor($device->id, $from, $to);

        $this->assertCount(2, $series);
        $this->assertEqualsWithDelta(-20.0, $series[0]['rx_power_dbm'], 0.01);
        $this->assertEqualsWithDelta(-21.0, $series[1]['rx_power_dbm'], 0.01);
    }

    public function test_custom_range_of_five_days_aggregates_hourly(): void
    {
        $device = $this->device();
        $from = now()->subMonths(3)->startOfDay();
        $to = $from->copy()->addDays(5);

        $base = $from->copy()->addDay()->startOfHour();
        $this->point($device, -20.0, $base->copy()->addMinutes(5));
        $this->point($device, -24.0, $base->copy()->addMinutes(45));

        $series = app(CpeSignalHistoryQueryService::class)->customSeriesFor($device->id, $from, $to);

        $this->assertCount(1, $series);
        $this->assertEqualsWithDelta(-22.0, $series[0]['rx_power_dbm'], 0.01);
    }

    public function test_custom_range_of_twenty_days_aggregates_daily(): void
    {
        $device = $this->device();
        $from = now()->subMonths(3)->startOfDay();
        $to = $from->copy()->addDays(20);

        $day = $from->copy()->addDays(3);
        $this->point($device, -18.0, $day->copy()->addHours(2));
        $this->point($device, -22.0, $day->copy()->addHours(20));

        $series = app(CpeSignalHistoryQueryService::class)->customSeriesFor($device->id, $from, $to);

        $this->assertCount(1, $series);
        $this->assertEqualsWithDelta(-20.0, $series[0]['rx_power_dbm'], 0.01);
    }

    public function test_custom_range_of_ninety_days_aggregates_weekly(): void
    {
        $device = $this->device();
        $from = now()->subMonths(6)->startOfDay();
        $to = $from->copy()->addDays(90);

        $point = $from->copy()->addDays(10);
        $this->point($device, -19.0, $point);

        $series = app(CpeSignalHistoryQueryService::class)->customSeriesFor($device->id, $from, $to);

        $this->assertCount(1, $series);
        $this->assertEqualsWithDelta(-19.0, $series[0]['rx_power_dbm'], 0.01);
    }

    public function test_custom_range_upper_bound_excludes_points_after_it(): void
    {
        $device = $this->device();
        $from = now()->subMonths(3)->startOfDay();
        $to = $from->copy()->addHours(6);

        $this->point($device, -20.0, $from->copy()->addHours(1));
        // Just after the upper bound — must be excluded.
        $this->point($device, -99.0, $to->copy()->addMinutes(1));

        $series = app(CpeSignalHistoryQueryService::class)->customSeriesFor($device->id, $from, $to);

        $this->assertCount(1, $series);
        $this->assertEqualsWithDelta(-20.0, $series[0]['rx_power_dbm'], 0.01);
    }
}
