<?php

namespace App\Livewire\Network;

use App\Enums\CpeSignalHistoryRange;
use App\Livewire\Concerns\ValidatesCustomHistoryRange;
use App\Models\CpeDevice;
use App\Services\Network\CpeSignalHistoryQueryService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * v0.8.3 — RX Power history graph for the CPE detail page's Status
 * Jaringan section, placed right next to the existing live RX Power field.
 * Reuses v0.8.2's DeviceTrafficGraph pattern verbatim: Chart.js against a
 * canvas inside a `wire:ignore` wrapper, updated via a dispatched browser
 * event rather than a Livewire re-render — see resources/js/app.js's
 * `window.signalHistoryChart` and this component's paired Blade view.
 *
 * TWO independent chart instances, not one — a deliberate revision (the
 * range tabs originally sat inline above the main graph; per real design
 * feedback referencing a SmartOLT-style "⋯" corner affordance, they moved
 * into a modal instead, the main page graph now ALWAYS shows the plain
 * 24h/Day view with no selector visible at all):
 *   - The main page graph: fixed at CpeSignalHistoryRange::Day, loaded
 *     once in mount(), never re-queried again — `$series`/`$state`.
 *   - The modal graph: independent range state
 *     (`$modalRange`/`$modalState`/`$modalSeries`), lazy-loaded only when
 *     the modal actually opens (openHistoryModal()), switchable across
 *     all 5 CpeSignalHistoryRange tabs from there.
 * Each has its own dispatched event name (`signal-history-series-updated`
 * vs `signal-history-modal-series-updated`) so switching the modal's tabs
 * can never accidentally mutate the main page chart or vice versa — two
 * separate `wire:ignore` Alpine scopes in the paired Blade view, both
 * built from the SAME `window.signalHistoryChart` factory (identical
 * rendering/tooltip/Y-axis-label logic in both places, no duplicated JS).
 *
 * The actual query is delegated to App\Services\Network\
 * CpeSignalHistoryQueryService — SQL-level aggregation for Week/Month/Year
 * (see that service's own docblock), unchanged by this UI revision.
 *
 * Three states, not two, computed identically for both the main graph and
 * the modal graph (deriveStateAndSeries()) — mirrors the null vs. empty
 * distinction already established for LibreNmsService's "no_sensor" vs
 * "unavailable" cells (v0.8.2), applied here to a different question:
 *   - 'no_history': zero rows/buckets at all in the selected range's
 *     window. Rendered as a plain message, no chart at all.
 *   - 'all_null': rows/buckets exist, but every one resolves to null
 *     rx_power_dbm (a real, confirmed-for-real scenario — see CLAUDE.md's
 *     "RX Power History (v0.8.3)" checkpoint section, device #138).
 *   - 'ok': at least one real reading/bucket average exists — renders the
 *     chart, individual null points rendered as genuine gaps
 *     (`spanGaps: false`), never a misleading 0 or a line drawn straight
 *     across the gap.
 */
class CpeSignalHistoryGraph extends Component
{
    use AuthorizesRequests, ValidatesCustomHistoryRange;

    public int $cpeDeviceId;

    public string $state = 'no_history';

    /** @var array<int, array{recorded_at: int, rx_power_dbm: ?float}> */
    public array $series = [];

    public bool $showHistoryModal = false;

    public string $modalRange = 'day';

    public string $modalState = 'no_history';

    /** @var array<int, array{recorded_at: int, rx_power_dbm: ?float}> */
    public array $modalSeries = [];

    public function mount(int $cpeDeviceId): void
    {
        $device = CpeDevice::findOrFail($cpeDeviceId);
        $this->authorize('view', $device);

        $this->cpeDeviceId = $cpeDeviceId;

        $this->loadSeries();
    }

    public function loadSeries(?CpeSignalHistoryQueryService $service = null): void
    {
        $service ??= app(CpeSignalHistoryQueryService::class);

        [$this->state, $this->series] = $this->deriveStateAndSeries(
            $service->seriesFor($this->cpeDeviceId, CpeSignalHistoryRange::Day)
        );

        // Chart.js's canvas lives inside a `wire:ignore` wrapper (see the
        // paired Blade view) — this dispatch is how it learns about fresh
        // data, same mechanism as v0.8.2's DeviceTrafficGraph.
        $this->dispatch('signal-history-series-updated', series: $this->series);
    }

    public function openHistoryModal(): void
    {
        $this->showHistoryModal = true;
        $this->customRangeMode = false;
        $this->modalRange = CpeSignalHistoryRange::default()->value;

        $this->loadModalSeries();
    }

    public function closeHistoryModal(): void
    {
        $this->showHistoryModal = false;
    }

    public function changeModalRange(string $range): void
    {
        // Validates via the enum's own backing — an unknown value simply
        // never matches a case, so this can't be tricked into an arbitrary
        // SQL grain by a forged wire:click payload.
        $this->customRangeMode = false;
        $this->modalRange = CpeSignalHistoryRange::from($range)->value;

        $this->loadModalSeries();
    }

    public function loadModalSeries(?CpeSignalHistoryQueryService $service = null): void
    {
        $service ??= app(CpeSignalHistoryQueryService::class);
        $range = CpeSignalHistoryRange::from($this->modalRange);

        [$this->modalState, $this->modalSeries] = $this->deriveStateAndSeries(
            $service->seriesFor($this->cpeDeviceId, $range)
        );

        $this->dispatch('signal-history-modal-series-updated', series: $this->modalSeries);
    }

    /**
     * v0.8.3 — "Custom" 6th tab (ValidatesCustomHistoryRange trait handles
     * the actual date validation). Grain is derived from the real
     * day-length of [$from, $to], not a named tab — see
     * CpeSignalHistoryQueryService::customSeriesFor()'s own docblock.
     */
    public function applyCustomRange(?CpeSignalHistoryQueryService $service = null): void
    {
        $bounds = $this->validateCustomRange();

        if ($bounds === null) {
            return;
        }

        [$from, $to] = $bounds;
        $service ??= app(CpeSignalHistoryQueryService::class);

        [$this->modalState, $this->modalSeries] = $this->deriveStateAndSeries(
            $service->customSeriesFor($this->cpeDeviceId, $from, $to)
        );

        $this->dispatch('signal-history-modal-series-updated', series: $this->modalSeries);
    }

    /**
     * @param  array<int, array{recorded_at: int, rx_power_dbm: ?float}>  $rows
     * @return array{0: string, 1: array<int, array{recorded_at: int, rx_power_dbm: ?float}>}
     */
    private function deriveStateAndSeries(array $rows): array
    {
        if ($rows === []) {
            return ['no_history', []];
        }

        if (collect($rows)->every(fn (array $row) => $row['rx_power_dbm'] === null)) {
            return ['all_null', $rows];
        }

        return ['ok', $rows];
    }

    public function render()
    {
        return view('livewire.network.cpe-signal-history-graph', [
            'ranges' => CpeSignalHistoryRange::cases(),
            'selectedModalRange' => CpeSignalHistoryRange::from($this->modalRange),
        ]);
    }
}
