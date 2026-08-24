<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CpeSignalHistoryRange;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\ContainerStatsHistory;
use App\Services\Network\DeviceMonitoringSummaryService;
use App\Services\Network\LibreNmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * v0.8.4 — read-only REST surface over the same LibreNMS-backed data
 * already shown on `/monitoring` (App\Livewire\Network\DeviceMonitoringList/
 * DeviceTrafficGraph) — built as the first foothold for a future WhatsApp
 * bot integration (per Agung's own framing: "cikal bakal integrasi
 * WhatsApp bot nanti, bukan dibangun sekarang, tapi datanya harus sudah
 * bisa diakses via API"), not itself the bot. Platform-level, same posture
 * as TaxComponentController — sits OUTSIDE the `reseller.context` group in
 * routes/api.php because monitoring is an ISP-infrastructure concern, not
 * a reseller-scoped business resource (a reseller has no `nas`/`OltDevice`
 * ownership concept the way it does customers/subscriptions).
 *
 * `devices()` delegates ALL of its row-building (the 'ok'/'no_sensor'/
 * 'unavailable' per-metric averaging) to DeviceMonitoringSummaryService —
 * see that class's own docblock for why it was extracted out of
 * DeviceMonitoringList specifically to make this controller possible
 * without duplicating that logic.
 */
class MonitoringController extends Controller
{
    use ApiResponds;

    public function devices(LibreNmsService $service, DeviceMonitoringSummaryService $summaryService): JsonResponse
    {
        $this->authorize('monitoring.view');

        $rows = array_map(
            fn (array $device) => $summaryService->buildRow($device, $service),
            $service->listDevices(),
        );

        return $this->success($rows, 'Daftar device monitoring');
    }

    /**
     * `range` reuses CpeSignalHistoryRange::fromApiParam() — see that
     * method's own docblock for why the same hourly/daily/weekly/monthly/
     * yearly vocabulary is shared across this endpoint and the CPE signal-
     * history one below, despite the enum's CPE-flavored name. No PHP-side
     * aggregation is needed here for wider ranges (unlike CPE signal
     * history's raw-row table) — LibreNmsService::getTrafficHistory()'s
     * `rrdtool xport` call already consolidates internally via RRDtool's
     * own RRA mechanism, so this just converts the range word to a second
     * count and passes it straight through.
     */
    public function deviceTraffic(Request $request, int $device, LibreNmsService $service): JsonResponse
    {
        $this->authorize('monitoring.view');

        $validated = $request->validate([
            'interface' => ['required', 'string'],
            'range' => ['nullable', 'string', 'in:hourly,daily,weekly,monthly,yearly'],
        ]);

        $range = $this->resolveRange($validated['range'] ?? null);

        $series = $service->getTrafficHistory($device, $validated['interface'], $range->windowHours() * 3600);

        return $this->success($series, 'Riwayat traffic device monitoring');
    }

    /**
     * v0.8.4 Bagian C — the latest App\Models\ContainerStatsHistory
     * snapshot (one row per container, all sharing the same `recorded_at`
     * from a single SyncContainerStats run — see that model's own
     * docblock), same data App\Livewire\Network\ContainerStatsList shows
     * on `/monitoring`. A plain indexed query, no service layer — same
     * "not worth its own abstraction" call already made for
     * CpeSignalHistoryGraph reading cpe_signal_history directly.
     */
    public function containers(): JsonResponse
    {
        $this->authorize('monitoring.view');

        $latestRecordedAt = ContainerStatsHistory::max('recorded_at');

        if ($latestRecordedAt === null) {
            return $this->success([], 'Belum ada data container stats');
        }

        $rows = ContainerStatsHistory::where('recorded_at', $latestRecordedAt)
            ->orderBy('container_name')
            ->get(['container_name', 'cpu_percent', 'memory_usage_mb', 'memory_limit_mb', 'network_rx_bytes', 'network_tx_bytes', 'disk_usage_mb', 'recorded_at']);

        return $this->success($rows, 'Snapshot stats container terakhir');
    }

    /**
     * v0.8.4 Bagian D — REST twin of App\Livewire\Network\
     * DeviceHistoryModal, calling the exact same
     * `LibreNmsService::getMetricHistory()` (extracted specifically so
     * both callers share one implementation, BOSS-006). `metric` is
     * required (`cpu`/`memory`/`temperature`) — unlike device-level
     * `?range=`, there's no sensible single default metric to assume.
     */
    public function deviceHistory(Request $request, int $device, LibreNmsService $service): JsonResponse
    {
        $this->authorize('monitoring.view');

        $validated = $request->validate([
            'metric' => ['required', 'string', 'in:cpu,memory,temperature'],
            'range' => ['nullable', 'string', 'in:hourly,daily,weekly,monthly,yearly'],
        ]);

        $range = $this->resolveRange($validated['range'] ?? null);

        $series = $service->getMetricHistory($device, $validated['metric'], $range->windowHours() * 3600);

        return $this->success($series, 'Riwayat metrik device monitoring');
    }

    /**
     * v0.8.4 Bagian D — REST twin of App\Livewire\Network\DeviceEditForm,
     * same `LibreNmsService::updateDevice()` whitelist (display_template/
     * community/port/snmpver — see that method's own docblock for why
     * hostname/ip/SNMPv3 fields are excluded). `monitoring.manage`, not
     * `.view` — this mutates LibreNMS state.
     */
    public function updateDevice(Request $request, int $device, LibreNmsService $service): JsonResponse
    {
        $this->authorize('monitoring.manage');

        $validated = $request->validate([
            'display_name' => ['nullable', 'string', 'max:255'],
            'community' => ['sometimes', 'required', 'string', 'max:255'],
            'port' => ['sometimes', 'required', 'integer', 'min:1', 'max:65535'],
            'snmp_version' => ['sometimes', 'required', 'in:v1,v2c'],
        ]);

        $fields = array_filter([
            'display_template' => $validated['display_name'] ?? null,
            'community' => $validated['community'] ?? null,
            'port' => $validated['port'] ?? null,
            'snmpver' => $validated['snmp_version'] ?? null,
        ], fn ($value) => $value !== null);

        $service->updateDevice($device, $fields);

        return $this->success(null, 'Device berhasil diperbarui');
    }

    /**
     * v0.8.4 Bagian D — REST twin of DeviceMonitoringList::removeDevice().
     * `monitoring.manage`, not `.view`. Destructive — LibreNMS's own
     * delete_device() drops that device's RRD history and port/sensor
     * rows too, not just the device row itself.
     */
    public function destroyDevice(int $device, LibreNmsService $service): JsonResponse
    {
        $this->authorize('monitoring.manage');

        $service->deleteDevice($device);

        return $this->success(null, 'Device berhasil dihapus dari LibreNMS');
    }

    private function resolveRange(?string $param): CpeSignalHistoryRange
    {
        if ($param === null) {
            return CpeSignalHistoryRange::default();
        }

        try {
            return CpeSignalHistoryRange::fromApiParam($param);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['range' => $e->getMessage()]);
        }
    }
}
