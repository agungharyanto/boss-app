<?php

namespace App\Services\Network;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v0.8.4 — extracted from `App\Livewire\Network\DeviceMonitoringList`
 * (v0.8.2), which was this logic's only caller until the new read-only
 * monitoring API (`App\Http\Controllers\Api\V1\MonitoringController`)
 * needed the exact same averaged/degraded row shape — rather than
 * duplicate the averaging + per-metric resilience logic in the
 * controller, it moved here so both the Livewire UI and the API call the
 * same code. `DeviceMonitoringList::buildRow()` now just delegates here.
 *
 * Each metric is fetched and degraded INDEPENDENTLY — one device's
 * LibreNMS call failing never blanks the whole row, and one metric
 * failing never hides that device's other metrics. Three distinct states,
 * not two:
 *   - 'ok'          real value(s), averaged across however many sensors of
 *                   that class the device has (e.g. the ZTE C300 OLT has 7
 *                   separate processor sensors, one per line card).
 *   - 'no_sensor'   the device genuinely has zero sensors of this class
 *                   (real, confirmed — e.g. the HSGQ-E04ID OLT has no CPU
 *                   or temperature OID at all) — NOT an error.
 *   - 'unavailable' the LibreNMS API call itself failed (network, 5xx,
 *                   timeout) — a genuine degraded-dependency state.
 * Availability% shows the 1-day duration specifically (LibreNMS always
 * returns 4 fixed durations; 1 day is the most relevant "is this thing up
 * right now" figure — a presentation choice, not a LibreNmsService
 * limitation).
 */
class DeviceMonitoringSummaryService
{
    /**
     * @param  array{device_id: int, hostname: string, sys_name: ?string, status: bool, uptime: ?int}  $device
     * @return array<string, mixed>
     */
    public function buildRow(array $device, LibreNmsService $service): array
    {
        return [
            'device_id' => $device['device_id'],
            'hostname' => $device['hostname'],
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
}
