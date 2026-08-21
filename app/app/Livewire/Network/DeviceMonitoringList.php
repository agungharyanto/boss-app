<?php

namespace App\Livewire\Network;

use App\Services\Network\LibreNmsService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Log;
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

    public function mount(?int $onlyDeviceId = null): void
    {
        $this->authorize('monitoring.view');

        $this->onlyDeviceId = $onlyDeviceId;
        $this->loadDevices();
    }

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

        $this->rows = array_map(fn (array $device) => $this->buildRow($device, $service), $devices);
    }

    /**
     * @param  array{device_id: int, hostname: string, sys_name: ?string, status: bool, uptime: ?int}  $device
     * @return array<string, mixed>
     */
    private function buildRow(array $device, LibreNmsService $service): array
    {
        return [
            'device_id' => $device['device_id'],
            'name' => $device['sys_name'] ?: $device['hostname'],
            'status' => $device['status'],
            'uptime' => $device['uptime'],
            'cpu' => $this->averagedMetricCell(fn () => $service->getCpuUsage($device['device_id']), 'usage_percent'),
            'memory' => $this->averagedMetricCell(fn () => $service->getMemoryUsage($device['device_id']), 'usage_percent'),
            'temperature' => $this->averagedMetricCell(fn () => $service->getTemperature($device['device_id']), 'value_celsius'),
            'availability' => $this->availabilityCell(fn () => $service->getAvailability($device['device_id'])),
        ];
    }

    /**
     * @return array{state: string, value: ?float}
     */
    private function averagedMetricCell(callable $fetch, string $valueKey): array
    {
        try {
            $readings = $fetch();
        } catch (Throwable $e) {
            Log::warning('LibreNMS metric unavailable', ['exception' => $e->getMessage()]);

            return ['state' => 'unavailable', 'value' => null];
        }

        if ($readings === []) {
            return ['state' => 'no_sensor', 'value' => null];
        }

        $values = array_filter(array_column($readings, $valueKey), fn ($v) => $v !== null);

        if ($values === []) {
            return ['state' => 'no_sensor', 'value' => null];
        }

        return ['state' => 'ok', 'value' => array_sum($values) / count($values)];
    }

    /**
     * @return array{state: string, value: ?float}
     */
    private function availabilityCell(callable $fetch): array
    {
        try {
            $durations = $fetch();
        } catch (Throwable $e) {
            Log::warning('LibreNMS availability unavailable', ['exception' => $e->getMessage()]);

            return ['state' => 'unavailable', 'value' => null];
        }

        $oneDay = collect($durations)->firstWhere('duration_seconds', 86400);

        if ($oneDay === null) {
            return ['state' => 'no_sensor', 'value' => null];
        }

        return ['state' => 'ok', 'value' => $oneDay['availability_percent']];
    }

    public function selectDevice(int $deviceId): void
    {
        $this->selectedDeviceId = $this->selectedDeviceId === $deviceId ? null : $deviceId;

        $this->dispatch('device-selected', deviceId: $this->selectedDeviceId);
    }

    public function render()
    {
        return view('livewire.network.device-monitoring-list');
    }
}
