<?php

namespace App\Livewire\Network;

use App\Services\Network\DeviceMonitoringSummaryService;
use App\Services\Network\LibreNmsService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * v0.8.2 — reusable device monitoring table (status/uptime/CPU%/Memory%/
 * Temperature/Availability%), backed by LibreNmsService. Built to be
 * mountable both on its own full page (/monitoring) and, later, embedded
 * on the main Dashboard with a narrower `onlyDeviceId` filter — see
 * CLAUDE.md's "Dashboard Monitoring (v0.8.2)" section.
 *
 * Each metric cell is fetched and degraded INDEPENDENTLY per device — one
 * device's LibreNMS call failing never blanks out the whole table, and one
 * metric failing for a device never hides that device's other metrics.
 * Three distinct cell states are rendered differently on purpose:
 *   - 'ok'        real value(s), averaged across however many sensors of
 *                  that class the device has (e.g. the ZTE C300 OLT has 7
 *                  separate processor sensors, one per line card).
 *   - 'no_sensor'  the device genuinely has zero sensors of this class
 *                  (confirmed real for several device/metric combinations
 *                  in this fleet, e.g. the HSGQ-E04ID OLT has no CPU or
 *                  temperature OID at all) — NOT an error.
 *   - 'unavailable' the LibreNMS API call itself failed (network, 5xx,
 *                  timeout) — a genuine degraded-dependency state.
 * Availability% shows the 1-day duration specifically (LibreNMS always
 * returns 4 fixed durations; 1 day is the most relevant "is this thing up
 * right now" figure for an ops table — 1 week/month/year are not shown
 * here, this is a presentation choice, not a LibreNmsService limitation).
 */
class DeviceMonitoringList extends Component
{
    use AuthorizesRequests;

    /**
     * When set, only this one device's row is shown — the filter a future
     * Dashboard placement uses to embed a single device's card instead of
     * the full fleet table.
     */
    public ?int $onlyDeviceId = null;

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public bool $pageUnavailable = false;

    public ?int $selectedDeviceId = null;

    public ?string $removeErrorMessage = null;

    public function mount(?int $onlyDeviceId = null): void
    {
        $this->authorize('monitoring.view');

        $this->onlyDeviceId = $onlyDeviceId;
        $this->loadDevices();
    }

    /**
     * v0.8.2-monitoring-fixes — AddMonitoringDeviceForm dispatches
     * monitoring-device-added after successfully onboarding a generic SNMP
     * device, so the new device shows up here without a manual page
     * reload. v0.8.4 Bagian D — DeviceEditForm/DeviceMonitoringList's own
     * removeDevice() dispatch monitoring-device-updated for the same
     * reason after an edit/remove. Both rely on LibreNmsService's own
     * Cache::forget('librenms:devices') on success — without that, this
     * reload would just re-serve the same stale cached list for up to
     * cache_ttl seconds.
     */
    #[On('monitoring-device-added')]
    #[On('monitoring-device-updated')]
    public function loadDevices(?LibreNmsService $service = null): void
    {
        $service ??= app(LibreNmsService::class);

        try {
            $devices = $service->listDevices();
        } catch (Throwable $e) {
            Log::warning('LibreNMS device list unavailable', ['exception' => $e->getMessage()]);
            $this->pageUnavailable = true;
            $this->rows = [];

            return;
        }

        $this->pageUnavailable = false;

        if ($this->onlyDeviceId !== null) {
            $devices = array_values(array_filter($devices, fn (array $d) => $d['device_id'] === $this->onlyDeviceId));
        }

        $summaryService = app(DeviceMonitoringSummaryService::class);
        $this->rows = array_map(fn (array $device) => $summaryService->buildRow($device, $service), $devices);
    }

    public function selectDevice(int $deviceId): void
    {
        $this->selectedDeviceId = $this->selectedDeviceId === $deviceId ? null : $deviceId;

        $this->dispatch('device-selected', deviceId: $this->selectedDeviceId);
    }

    /**
     * v0.8.4 Bagian D — "Riwayat" per row. Deliberately a dispatched event
     * to a SEPARATE sibling component (App\Livewire\Network\
     * DeviceHistoryModal), same "table component dispatches, a sibling
     * listens" architecture already established for device-selected →
     * DeviceTrafficGraph — keeps this table's own state surface unchanged
     * rather than bolting modal/metric/range state onto it.
     */
    public function openHistory(int $deviceId, string $name): void
    {
        $this->dispatch('device-history-requested', deviceId: $deviceId, deviceName: $name);
    }

    /**
     * v0.8.4 — "Log" per row, same dispatched-event pattern as
     * openHistory() above, to App\Livewire\Network\DeviceSyslogModal.
     */
    public function openSyslog(int $deviceId, string $name): void
    {
        $this->dispatch('device-syslog-requested', deviceId: $deviceId, deviceName: $name);
    }

    /**
     * v0.8.4 Bagian D — same dispatched-event pattern as openHistory()
     * above, to App\Livewire\Network\DeviceEditForm.
     */
    public function openEdit(int $deviceId): void
    {
        $this->dispatch('device-edit-requested', deviceId: $deviceId);
    }

    /**
     * v0.8.4 Bagian D — gated by `wire:confirm` in the Blade view
     * (destructive: LibreNMS's own delete_device() drops that device's RRD
     * history and port/sensor rows too, not just the device row). Requires
     * `monitoring.manage`, not `.view` — same posture as
     * AddMonitoringDeviceForm/DeviceEditForm.
     */
    public function removeDevice(int $deviceId, LibreNmsService $service): void
    {
        $this->authorize('monitoring.manage');

        $this->removeErrorMessage = null;

        try {
            $service->deleteDevice($deviceId);
        } catch (Throwable $e) {
            Log::warning("DeviceMonitoringList: gagal menghapus device #{$deviceId} — {$e->getMessage()}");
            $this->removeErrorMessage = $e->getMessage();

            return;
        }

        $this->loadDevices();
    }

    public function render()
    {
        return view('livewire.network.device-monitoring-list');
    }
}
