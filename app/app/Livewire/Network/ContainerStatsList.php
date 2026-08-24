<?php

namespace App\Livewire\Network;

use App\Models\ContainerStatsHistory;
use App\Services\Infra\ContainerStatsService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * v0.8.4 Bagian C — "Container BOSS App" section on /monitoring, below the
 * device table. Reads the LATEST snapshot per container from
 * container_stats_history (written every 5 minutes by
 * App\Console\Commands\SyncContainerStats) — no live docker-stats-proxy
 * call happens on page load, same "history table is the source of truth
 * for a UI list" posture as CpeSignalHistoryGraph reading cpe_signal_
 * history directly rather than a service layer (a single indexed query
 * isn't business logic worth its own abstraction here either).
 *
 * "Latest" = every row sharing the single most recent `recorded_at` value
 * — every container in one SyncContainerStats run is written with the
 * SAME recorded_at (ContainerStatsService::syncAll() computes it once per
 * run), so this is a plain equality filter, not a per-container MAX()
 * subquery.
 *
 * v0.8.3 — `$rows` (flat, alphabetical) is kept as-is for backward
 * compatibility; `$groupedRows` is the new VPN/LibreNMS/BOSS App Core/
 * Lainnya grouping the Blade view actually renders, derived from the same
 * `$rows` via ContainerStatsService::groupFor()'s explicit mapping (see
 * that class's own docblock). A group with zero rows this cycle (e.g. no
 * `container_stats_history` rows at all yet for any LibreNMS container)
 * is simply absent from `$groupedRows` — never rendered as an empty
 * section — rather than every category always appearing regardless of
 * data.
 */
class ContainerStatsList extends Component
{
    use AuthorizesRequests;

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $groupedRows = [];

    public bool $noData = false;

    public function mount(): void
    {
        $this->authorize('monitoring.view');
        $this->loadStats();
    }

    public function loadStats(): void
    {
        $latestRecordedAt = ContainerStatsHistory::max('recorded_at');

        if ($latestRecordedAt === null) {
            $this->noData = true;
            $this->rows = [];
            $this->groupedRows = [];

            return;
        }

        $this->noData = false;

        $this->rows = ContainerStatsHistory::where('recorded_at', $latestRecordedAt)
            ->orderBy('container_name')
            ->get()
            ->map(fn (ContainerStatsHistory $row) => [
                'container_name' => $row->container_name,
                'cpu_percent' => $row->cpu_percent,
                'memory_usage_mb' => $row->memory_usage_mb,
                'memory_limit_mb' => $row->memory_limit_mb,
                'network_rx_bytes' => $row->network_rx_bytes,
                'network_tx_bytes' => $row->network_tx_bytes,
                'disk_usage_mb' => $row->disk_usage_mb,
                'recorded_at' => $row->recorded_at,
            ])
            ->all();

        $byGroup = collect($this->rows)->groupBy(
            fn (array $row) => ContainerStatsService::groupFor($row['container_name'])
        );

        $this->groupedRows = collect(ContainerStatsService::GROUP_ORDER)
            ->filter(fn (string $group) => $byGroup->has($group))
            ->mapWithKeys(fn (string $group) => [$group => $byGroup->get($group)->values()->all()])
            ->all();
    }

    public function render()
    {
        return view('livewire.network.container-stats-list');
    }
}
