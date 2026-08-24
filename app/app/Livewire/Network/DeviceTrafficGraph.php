<?php

namespace App\Livewire\Network;

use App\Enums\CpeSignalHistoryRange;
use App\Livewire\Concerns\ValidatesCustomHistoryRange;
use App\Services\Network\LibreNmsService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * v0.8.2 — reusable per-device traffic graph. Independently mountable with
 * an explicit `deviceId`/`ifName` (the shape a future Dashboard placement
 * would use, one fixed device/interface), or mountable bare and driven by
 * the sibling DeviceMonitoringList's `device-selected` event (the shape
 * /monitoring uses — click a row, the graph below it updates) — see
 * CLAUDE.md's "Dashboard Monitoring (v0.8.2)" section.
 *
 * Data comes from LibreNmsService::getTrafficHistory(), which reads
 * LibreNMS's own RRD files directly via `rrdtool xport --json` — LibreNMS's
 * REST API has no raw time-series JSON endpoint in this version (its only
 * traffic-graph route renders a rendered SVG/PNG image, not data). Chart.js
 * (resources/js/app.js, `window.Chart`) renders it client-side — no other
 * chart library exists yet in this codebase, chosen per the sprint brief.
 *
 * v0.8.4 — "Riwayat" modal, same INTERNAL-modal pattern as
 * CpeSignalHistoryGraph (v0.8.3) — reused here because this component is
 * architecturally like that one (a single self-contained graph with its
 * own already-selected target: this component tracks its own device+
 * interface, CpeSignalHistoryGraph tracks its own CPE device), unlike
 * DeviceMonitoringList's per-ROW "Riwayat" (v0.8.4 Bagian D), which
 * dispatches to a SEPARATE sibling component because a table has many
 * independent rows, not one single already-selected target. Reuses
 * CpeSignalHistoryRange's 5-tab Jam/Hari/Minggu/Bulan/Tahun vocabulary —
 * a third unrelated cross-purpose reuse of that enum in this codebase
 * (RX Power history, the v0.8.4 API's `?range=`, and now this).
 */
class DeviceTrafficGraph extends Component
{
    use AuthorizesRequests, ValidatesCustomHistoryRange;

    public ?int $deviceId = null;

    public ?string $selectedIfName = null;

    public int $rangeSeconds = 1800;

    /** @var array<int, array{port_id: int, if_name: string, if_oper_status: ?string}> */
    public array $availablePorts = [];

    /** @var array<int, array{timestamp: int, in_bytes_per_second: ?float, out_bytes_per_second: ?float}> */
    public array $series = [];

    public string $state = 'empty'; // empty|ok|no_sensor|unavailable

    public bool $showHistoryModal = false;

    public string $modalRange = 'day';

    public string $modalState = 'empty';

    /** @var array<int, array{timestamp: int, in_bytes_per_second: ?float, out_bytes_per_second: ?float}> */
    public array $modalSeries = [];

    public function mount(?int $deviceId = null, ?string $ifName = null, int $rangeSeconds = 1800): void
    {
        $this->authorize('monitoring.view');

        $this->rangeSeconds = $rangeSeconds;
        $this->deviceId = $deviceId;
        $this->selectedIfName = $ifName;

        if ($this->deviceId !== null) {
            $this->loadPorts();
            $this->loadSeries();
        }
    }

    #[On('device-selected')]
    public function changeDevice(?int $deviceId): void
    {
        $this->deviceId = $deviceId;
        $this->selectedIfName = null;
        $this->series = [];

        if ($deviceId === null) {
            $this->state = 'empty';
            $this->availablePorts = [];

            return;
        }

        $this->loadPorts();
        $this->loadSeries();
    }

    public function updatedSelectedIfName(): void
    {
        $this->loadSeries();
    }

    private function loadPorts(?LibreNmsService $service = null): void
    {
        $service ??= app(LibreNmsService::class);

        try {
            $this->availablePorts = $service->listPorts($this->deviceId);
        } catch (Throwable $e) {
            Log::warning('LibreNMS port list unavailable', ['exception' => $e->getMessage()]);
            $this->availablePorts = [];
            $this->state = 'unavailable';

            return;
        }

        if ($this->selectedIfName === null) {
            $preferred = collect($this->availablePorts)->firstWhere('if_oper_status', 'up')
                ?? $this->availablePorts[0] ?? null;

            $this->selectedIfName = $preferred['if_name'] ?? null;
        }
    }

    public function loadSeries(?LibreNmsService $service = null): void
    {
        if ($this->deviceId === null || $this->selectedIfName === null) {
            $this->series = [];
            $this->state = 'empty';

            return;
        }

        $service ??= app(LibreNmsService::class);

        try {
            $this->series = $service->getTrafficHistory($this->deviceId, $this->selectedIfName, $this->rangeSeconds);
            $this->state = 'ok';
        } catch (Throwable $e) {
            Log::warning('LibreNMS traffic history unavailable', ['exception' => $e->getMessage()]);
            $this->series = [];
            $this->state = 'unavailable';
        }

        // Chart.js's canvas lives inside a `wire:ignore` wrapper (see the
        // paired Blade view) — this dispatch is how it learns about fresh
        // data instead of a Livewire DOM morph, which would never reach it.
        $this->dispatch('traffic-series-updated', series: $this->series, ifName: $this->selectedIfName);
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
        // Validates via the enum's own backing, same guard already used by
        // CpeSignalHistoryGraph::changeModalRange() — an unknown value
        // simply never matches a case.
        $this->customRangeMode = false;
        $this->modalRange = CpeSignalHistoryRange::from($range)->value;

        $this->loadModalSeries();
    }

    public function loadModalSeries(?LibreNmsService $service = null): void
    {
        if ($this->deviceId === null || $this->selectedIfName === null) {
            $this->modalState = 'empty';
            $this->modalSeries = [];
            $this->dispatch('traffic-modal-series-updated', series: [], ifName: null);

            return;
        }

        $service ??= app(LibreNmsService::class);
        $rangeSeconds = CpeSignalHistoryRange::from($this->modalRange)->windowHours() * 3600;

        try {
            $this->modalSeries = $service->getTrafficHistory($this->deviceId, $this->selectedIfName, $rangeSeconds);
            $this->modalState = 'ok';
        } catch (Throwable $e) {
            Log::warning('LibreNMS traffic history (modal) unavailable', ['exception' => $e->getMessage()]);
            $this->modalSeries = [];
            $this->modalState = 'unavailable';
        }

        $this->dispatch('traffic-modal-series-updated', series: $this->modalSeries, ifName: $this->selectedIfName);
    }

    /**
     * v0.8.3 — "Custom" 6th tab. Same `?Carbon $endAt` pass-through as
     * DeviceHistoryModal::applyCustomRange() — see LibreNmsService's own
     * docblock for why absolute timestamps are needed here instead of
     * always-relative-to-now.
     *
     * **Real bug fixed here, same root cause as DeviceHistoryModal's own
     * fix (see that method's docblock + CLAUDE.md for the full incident)**:
     * `$to->diffInSeconds($from)` returns a NEGATIVE value for this call
     * order, inverting the `-s`/`-e` window sent to rrdtool and making
     * every custom-range traffic query fail outright. `abs()` + an
     * explicit `(int)` cast fixes both the sign and the float-precision
     * truncation at once.
     */
    public function applyCustomRange(?LibreNmsService $service = null): void
    {
        $bounds = $this->validateCustomRange();

        if ($bounds === null) {
            return;
        }

        if ($this->deviceId === null || $this->selectedIfName === null) {
            $this->modalState = 'empty';
            $this->modalSeries = [];
            $this->dispatch('traffic-modal-series-updated', series: [], ifName: null);

            return;
        }

        [$from, $to] = $bounds;
        $service ??= app(LibreNmsService::class);
        $rangeSeconds = (int) abs($to->diffInSeconds($from));

        try {
            $this->modalSeries = $service->getTrafficHistory($this->deviceId, $this->selectedIfName, $rangeSeconds, $to);
            $this->modalState = 'ok';
        } catch (Throwable $e) {
            Log::warning('LibreNMS traffic history (custom modal) unavailable', ['exception' => $e->getMessage()]);
            $this->modalSeries = [];
            $this->modalState = 'unavailable';
        }

        $this->dispatch('traffic-modal-series-updated', series: $this->modalSeries, ifName: $this->selectedIfName);
    }

    public function render()
    {
        return view('livewire.network.device-traffic-graph', [
            'ranges' => CpeSignalHistoryRange::cases(),
        ]);
    }
}
