<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CpeSignalHistoryRange;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\RebootCpeDeviceRequest;
use App\Http\Requests\SetCpeWifiCredentialsRequest;
use App\Http\Resources\CpeActionLogResource;
use App\Http\Resources\CpeConnectedHostResource;
use App\Http\Resources\CpeDeviceResource;
use App\Models\CpeDevice;
use App\Services\Network\CpeActionService;
use App\Services\Network\CpeSignalHistoryQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The device ROW itself is read-only (no store/update/destroy) — binding is
 * fully automatic via CpeBindingService::bindFromWorkOrder(), never a
 * user-facing create action, per the locked decision "otomatis dari
 * Installation, bukan input admin". reboot()/setWifi()/actions() (v0.7.4)
 * act ON an already-bound device, not on the row's own fields.
 */
class CpeDeviceController extends Controller
{
    use ApiResponds;

    /**
     * BelongsToResellerScope narrows this to the reseller's own devices; an
     * ISP admin (no context) sees every device including direct ones —
     * same posture as OdpController::index().
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CpeDevice::class);

        $devices = CpeDevice::query()
            ->with(['customer', 'reseller'])
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            CpeDeviceResource::collection($devices->items()),
            'Daftar perangkat CPE',
            ['pagination' => [
                'current_page' => $devices->currentPage(),
                'per_page' => $devices->perPage(),
                'total' => $devices->total(),
                'last_page' => $devices->lastPage(),
            ]]
        );
    }

    public function show(CpeDevice $cpeDevice): JsonResponse
    {
        $this->authorize('view', $cpeDevice);

        return $this->success(new CpeDeviceResource($cpeDevice->load(['customer', 'reseller'])));
    }

    /**
     * Always 200 with the CpeActionLog, whether delivery ended up
     * `delivered` or `failed` — the log ITSELF is the source of truth for
     * what happened, an HTTP error code here would wrongly suggest the API
     * call itself was malformed. `message` is deliberately honest either
     * way: never "berhasil reboot", only that the command was sent.
     */
    public function reboot(RebootCpeDeviceRequest $request, CpeDevice $cpeDevice, CpeActionService $service): JsonResponse
    {
        $log = $service->reboot($cpeDevice, $request->user());

        return $this->success(
            new CpeActionLogResource($log),
            $log->status->value === 'delivered'
                ? 'Perintah reboot terkirim — perangkat akan reboot saat menerima perintah ini (instan kalau Connection Request berhasil, atau saat koneksi berikutnya kalau tidak).'
                : 'Perintah reboot GAGAL dikirim.'
        );
    }

    public function setWifi(SetCpeWifiCredentialsRequest $request, CpeDevice $cpeDevice, CpeActionService $service): JsonResponse
    {
        $log = $service->setWifiCredentials(
            $cpeDevice,
            $request->validated('ssid'),
            $request->validated('password'),
            $request->user(),
            $request->validated('ssid_index') ?? 1,
        );

        return $this->success(
            new CpeActionLogResource($log),
            $log->status->value === 'delivered'
                ? 'Perintah ganti WiFi terkirim — akan diterapkan saat perangkat menerima perintah ini (instan kalau Connection Request berhasil, atau saat koneksi berikutnya kalau tidak).'
                : 'Perintah ganti WiFi GAGAL dikirim.'
        );
    }

    public function actions(Request $request, CpeDevice $cpeDevice): JsonResponse
    {
        $this->authorize('view', $cpeDevice);

        $logs = $cpeDevice->actionLogs()
            ->with('performedBy')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            CpeActionLogResource::collection($logs->items()),
            'Riwayat aksi perangkat CPE',
            ['pagination' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ]]
        );
    }

    /**
     * `?active_only=true` filters to currently-active hosts only — default
     * (no param, or any other value) returns the full history including
     * hosts marked inactive by App\Services\Network\CpeConnectedHostsService.
     * Sorted `last_seen_at` desc either way — most recently seen first.
     */
    public function connectedHosts(Request $request, CpeDevice $cpeDevice): JsonResponse
    {
        $this->authorize('view', $cpeDevice);

        $hosts = $cpeDevice->connectedHosts()
            ->when($request->boolean('active_only'), fn ($query) => $query->where('is_active', true))
            ->orderByDesc('last_seen_at')
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            CpeConnectedHostResource::collection($hosts->items()),
            'Daftar client TR-069 perangkat CPE',
            ['pagination' => [
                'current_page' => $hosts->currentPage(),
                'per_page' => $hosts->perPage(),
                'total' => $hosts->total(),
                'last_page' => $hosts->lastPage(),
            ]]
        );
    }

    /**
     * v0.8.4 — read-only REST wrapper over the exact same query the
     * "Riwayat" modal already uses (App\Livewire\Network\
     * CpeSignalHistoryGraph → CpeSignalHistoryQueryService), for the
     * future WhatsApp-bot integration foothold — see MonitoringController's
     * own docblock for the shared reasoning. `?range=` reuses
     * CpeSignalHistoryRange::fromApiParam() (default Day when omitted),
     * same hourly/daily/weekly/monthly/yearly vocabulary as
     * `GET /monitoring/devices/{device}/traffic`. Response rows are
     * remapped to the plain `{timestamp, rx_power_dbm}` shape (renaming
     * the internal `recorded_at` key) — a stable, minimal external
     * contract independent of this service's own internal field naming.
     */
    public function signalHistory(Request $request, CpeDevice $cpeDevice, CpeSignalHistoryQueryService $service): JsonResponse
    {
        $this->authorize('view', $cpeDevice);

        $validated = $request->validate([
            'range' => ['nullable', 'string', 'in:hourly,daily,weekly,monthly,yearly'],
        ]);

        $range = isset($validated['range'])
            ? CpeSignalHistoryRange::fromApiParam($validated['range'])
            : CpeSignalHistoryRange::default();

        $series = array_map(
            fn (array $point) => [
                'timestamp' => $point['recorded_at'],
                'rx_power_dbm' => $point['rx_power_dbm'],
            ],
            $service->seriesFor($cpeDevice->id, $range),
        );

        return $this->success($series, 'Riwayat RX Power perangkat CPE');
    }
}
