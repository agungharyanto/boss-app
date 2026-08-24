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
 * v0.8.4 Bagian D — "Riwayat" modal for DeviceMonitoringList, opened via a
 * dispatched `device-history-requested` event (same sibling-component
 * pattern already established for device-selected → DeviceTrafficGraph),
 * not bolted onto DeviceMonitoringList's own state. Reuses
 * CpeSignalHistoryRange's 5-tab Jam/Hari/Minggu/Bulan/Tahun vocabulary
 * (v0.8.3) for a SECOND, unrelated purpose — the "5 named time windows"
 * concept isn't CPE-specific, same cross-purpose reuse already established
 * for the v0.8.4 API's own `?range=` mapping.
 *
 * Unlike getTrafficHistory() (one interface, one series), a device can have
 * SEVERAL sensors of the same class (a real, confirmed case in this fleet —
 * the ZTE C300 OLT has 7 processor sensors, one per line card) — every
 * sensor's own history is fetched and shown as a SEPARATE line in the same
 * chart, never averaged away, so an ops user can tell which specific
 * line card/mempool is under load. `App\Services\Network\LibreNmsService::
 * getCpuHistory()`/`getMemoryHistory()`/`getTemperatureHistory()` do the
 * actual per-sensor rrdtool xport call — no PHP-side aggregation here.
 *
 * Chart x-axis labels are derived from the FIRST sensor's own timestamps
 * (same simplification already accepted for DeviceTrafficGraph/
 * CpeSignalHistoryGraph's own single-series charts) — every sensor on the
 * same device is polled on the same interval in practice, so this is a
 * reasonable, not a perfect, alignment assumption; documented here rather
 * than silently relied upon.
 *
 * Three states, not two, same distinction already established elsewhere in
 * this codebase for a LibreNMS-backed metric:
 *   - 'no_sensor'   the device genuinely has zero sensors of this class
 *                    (getCpuUsage()/getMemoryUsage()/getTemperature()
 *                    already establish this is real, not an error).
 *   - 'unavailable' the sensor list call itself failed, OR every
 *                    individual sensor's history call failed — a genuine
 *                    degraded-dependency state.
 *   - 'ok'          at least one sensor's history loaded — sensors whose
 *                    OWN history call failed are silently dropped from the
 *                    chart rather than blanking the whole modal (logged,
 *                    not surfaced as a per-sensor error in the UI — this
 *                    modal shows a chart, not a diagnostics panel).
 */
class DeviceHistoryModal extends Component
{
    use AuthorizesRequests, ValidatesCustomHistoryRange;

    public bool $showModal = false;

    public ?int $deviceId = null;

    public string $deviceName = '';

    public string $metric = 'cpu';

    public string $range = 'day';

    public string $state = 'no_history';

    /** @var array<int, array{sensor_id: int, label: string, points: array<int, array{timestamp: int, value: ?float}>}> */
    public array $series = [];

    public function mount(): void
    {
        $this->authorize('monitoring.view');
    }

    #[On('device-history-requested')]
    public function open(int $deviceId, string $deviceName): void
    {
        $this->authorize('monitoring.view');

        $this->deviceId = $deviceId;
        $this->deviceName = $deviceName;
        $this->metric = 'cpu';
        $this->range = CpeSignalHistoryRange::default()->value;
        $this->customRangeMode = false;
        $this->showModal = true;

        $this->loadHistory();
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }

    /**
     * If Custom mode is currently active (a valid range was already
     * applied), switching the metric re-applies THAT same custom range
     * rather than silently dropping back to a preset — the date inputs
     * still hold the values the admin typed, only the metric changes.
     */
    public function changeMetric(string $metric): void
    {
        if (! in_array($metric, ['cpu', 'memory', 'temperature'], true)) {
            return;
        }

        $this->metric = $metric;
        $this->customRangeMode ? $this->applyCustomRange() : $this->loadHistory();
    }

    public function changeRange(string $range): void
    {
        // Validates via the enum's own backing, same guard already used by
        // CpeSignalHistoryGraph::changeModalRange() — an unknown value
        // simply never matches a case.
        $this->customRangeMode = false;
        $this->range = CpeSignalHistoryRange::from($range)->value;
        $this->loadHistory();
    }

    public function loadHistory(?LibreNmsService $service = null): void
    {
        if ($this->deviceId === null) {
            return;
        }

        $service ??= app(LibreNmsService::class);
        $rangeSeconds = CpeSignalHistoryRange::from($this->range)->windowHours() * 3600;

        try {
            $series = $service->getMetricHistory($this->deviceId, $this->metric, $rangeSeconds);
        } catch (Throwable $e) {
            Log::warning("DeviceHistoryModal: gagal ambil riwayat {$this->metric} untuk device #{$this->deviceId} — {$e->getMessage()}");
            $this->state = 'unavailable';
            $this->series = [];
            $this->dispatchSeriesUpdated();

            return;
        }

        $this->state = $series === [] ? 'no_sensor' : 'ok';
        $this->series = $series;
        $this->dispatchSeriesUpdated();
    }

    /**
     * v0.8.3 — "Custom" 6th tab. `$endAt` is passed straight through to
     * LibreNmsService (see its own `?Carbon $endAt` parameter added for
     * this) so `-s`/`-e` become absolute timestamps instead of "N seconds
     * before now" — a real "Dari ... Sampai ..." range can legitimately
     * end in the past, not always at the moment of the request.
     *
     * **Real bug fixed here (found via a genuine reported 500, see
     * CLAUDE.md)**: `$to->diffInSeconds($from)` returns a NEGATIVE value in
     * this Carbon version for this call order (`$to` chronologically after
     * `$from`) — confirmed directly, not assumed — which fed a negative
     * `$rangeSeconds` into `LibreNmsService`'s `-s`/`-e` window builder,
     * inverting `-s`/`-e` (start AFTER end) and making rrdtool reject
     * EVERY custom-range query outright ("start should be less than end").
     * `abs()` + an explicit `(int)` cast (Carbon's `diffInSeconds()` also
     * carries microsecond precision, i.e. returns a float, which would
     * otherwise silently truncate) fixes both issues at once.
     */
    public function applyCustomRange(?LibreNmsService $service = null): void
    {
        $bounds = $this->validateCustomRange();

        if ($bounds === null) {
            return;
        }

        if ($this->deviceId === null) {
            return;
        }

        [$from, $to] = $bounds;
        $service ??= app(LibreNmsService::class);
        $rangeSeconds = (int) abs($to->diffInSeconds($from));

        try {
            $series = $service->getMetricHistory($this->deviceId, $this->metric, $rangeSeconds, $to);
        } catch (Throwable $e) {
            Log::warning("DeviceHistoryModal: gagal ambil riwayat custom {$this->metric} untuk device #{$this->deviceId} — {$e->getMessage()}");
            $this->state = 'unavailable';
            $this->series = [];
            $this->dispatchSeriesUpdated();

            return;
        }

        $this->state = $series === [] ? 'no_sensor' : 'ok';
        $this->series = $series;
        $this->dispatchSeriesUpdated();
    }

    private function dispatchSeriesUpdated(): void
    {
        $this->dispatch('device-history-series-updated', series: $this->series, unit: $this->metricUnit());
    }

    public function metricUnit(): string
    {
        return match ($this->metric) {
            'cpu', 'memory' => '%',
            'temperature' => '°C',
            default => '',
        };
    }

    public function render()
    {
        return view('livewire.network.device-history-modal', [
            'ranges' => CpeSignalHistoryRange::cases(),
        ]);
    }
}
