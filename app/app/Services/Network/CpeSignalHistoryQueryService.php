<?php

namespace App\Services\Network;

use App\Enums\CpeSignalHistoryRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-side query for the CPE detail page's RX Power history graph
 * (App\Livewire\Network\CpeSignalHistoryGraph) — a genuinely separate
 * concern from App\Services\Network\CpeSignalHistoryService, which only
 * ever WRITES to cpe_signal_history (the 20-minute scheduler). Split into
 * its own service (unlike the graph component's original single-table
 * Eloquent query, which didn't warrant one) because the Week/Month/Year
 * tabs need real SQL-level aggregation — pulling ~26,000 raw rows for a
 * 365-day view just to average them in PHP is exactly the kind of
 * resource cost this sprint's own "paling ringan" brief was written
 * against.
 *
 * `AVG(rx_power_dbm)` is standard SQL and already ignores NULL rows on its
 * own — a bucket whose every row is null correctly comes back NULL from
 * the aggregate itself, no extra NULL-handling code needed to keep a
 * genuinely-unreadable period rendering as a gap rather than a false 0.
 *
 * Bucket boundaries are computed differently per DB driver (SQLite in
 * tests, per phpunit.xml; PostgreSQL in production) — no portable single
 * SQL expression exists for "truncate to the start of the hour/day/ISO
 * week" across both. `recorded_at` has no explicit timezone component
 * (see the migration), so both drivers operate on the same naive local
 * timestamp with no conversion step needed.
 */
class CpeSignalHistoryQueryService
{
    /**
     * @return array<int, array{recorded_at: int, rx_power_dbm: ?float}>
     */
    public function seriesFor(int $cpeDeviceId, CpeSignalHistoryRange $range): array
    {
        $since = now()->subHours($range->windowHours());
        $grain = $range->aggregationGrain();

        return $grain === null
            ? $this->rawSeries($cpeDeviceId, $since, null)
            : $this->aggregatedSeries($cpeDeviceId, $since, null, $grain);
    }

    /**
     * v0.8.3 — Custom Date Range tab (CLAUDE.md). Unlike seriesFor() above
     * (always open-ended up to "now"), a custom range has a real upper
     * bound too — both bounds are passed straight through to
     * rawSeries()/aggregatedSeries() below, which already accepted an
     * optional `$until` for exactly this. Grain is derived from the
     * ACTUAL day-length of [$from, $to] via
     * CpeSignalHistoryRange::aggregationGrainForDays() — never from a
     * named tab, since Custom has none.
     *
     * @return array<int, array{recorded_at: int, rx_power_dbm: ?float}>
     */
    public function customSeriesFor(int $cpeDeviceId, Carbon $from, Carbon $to): array
    {
        $grain = CpeSignalHistoryRange::aggregationGrainForDays($from->diffInDays($to, true));

        return $grain === null
            ? $this->rawSeries($cpeDeviceId, $from, $to)
            : $this->aggregatedSeries($cpeDeviceId, $from, $to, $grain);
    }

    /**
     * @return array<int, array{recorded_at: int, rx_power_dbm: ?float}>
     */
    private function rawSeries(int $cpeDeviceId, Carbon $since, ?Carbon $until): array
    {
        return DB::table('cpe_signal_history')
            ->where('cpe_device_id', $cpeDeviceId)
            ->where('recorded_at', '>=', $since)
            ->when($until !== null, fn ($query) => $query->where('recorded_at', '<=', $until))
            ->orderBy('recorded_at')
            ->get(['rx_power_dbm', 'recorded_at'])
            ->map(fn ($row) => [
                'recorded_at' => Carbon::parse($row->recorded_at)->getTimestamp(),
                'rx_power_dbm' => $row->rx_power_dbm !== null ? (float) $row->rx_power_dbm : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{recorded_at: int, rx_power_dbm: ?float}>
     */
    private function aggregatedSeries(int $cpeDeviceId, Carbon $since, ?Carbon $until, string $grain): array
    {
        $bucket = $this->bucketExpression($grain);

        return DB::table('cpe_signal_history')
            ->selectRaw("{$bucket} as bucket_start, AVG(rx_power_dbm) as avg_rx_power_dbm")
            ->where('cpe_device_id', $cpeDeviceId)
            ->where('recorded_at', '>=', $since)
            ->when($until !== null, fn ($query) => $query->where('recorded_at', '<=', $until))
            ->groupByRaw($bucket)
            ->orderBy('bucket_start')
            ->get()
            ->map(fn ($row) => [
                'recorded_at' => Carbon::parse($row->bucket_start)->getTimestamp(),
                'rx_power_dbm' => $row->avg_rx_power_dbm !== null ? round((float) $row->avg_rx_power_dbm, 2) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * `week` truncates to Monday (ISO 8601) on both drivers — PostgreSQL's
     * `date_trunc('week', ...)` is Monday-start natively; the SQLite
     * expression is the standard `-6 days` + `weekday 1` idiom for the
     * same result (verified against every weekday: a date already ON
     * Monday must resolve to itself, not roll forward/back a full week —
     * the naive single-modifier version `'weekday 1', '-7 days'` gets this
     * exact boundary case wrong).
     */
    private function bucketExpression(string $grain): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return match ($grain) {
                'hour' => "strftime('%Y-%m-%d %H:00:00', recorded_at)",
                'day' => 'date(recorded_at)',
                'week' => "date(recorded_at, '-6 days', 'weekday 1')",
            };
        }

        return match ($grain) {
            'hour' => "date_trunc('hour', recorded_at)",
            'day' => "date_trunc('day', recorded_at)",
            'week' => "date_trunc('week', recorded_at)",
        };
    }
}
