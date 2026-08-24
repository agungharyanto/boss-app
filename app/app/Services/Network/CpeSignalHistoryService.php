<?php

namespace App\Services\Network;

use App\Enums\CpeDeviceStatus;
use App\Models\CpeDevice;
use App\Models\CpeParameterMap;
use App\Models\CpeSignalHistory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes one App\Models\CpeSignalHistory row per currently-online CpeDevice,
 * every ~20 minutes (see App\Console\Commands\SyncCpeSignalHistory) — closes
 * the gap found investigating the CPE detail page's existing RX Power
 * display: it's a live resolve against whatever GenieACS's stored tree
 * currently has, which is NEVER proactively refreshed (no `declare()` for
 * the optical DDM object in docker/genieacs/presets/default.js) except by
 * the manual "Sync Sekarang" button — so nothing periodic ever updated it,
 * and nothing anywhere stored a history of it. See CLAUDE.md's "RX Power
 * History (v0.8.3)" for the full investigation trail.
 *
 * Deliberately a SEPARATE service/command from CpeDeviceStatusSyncService
 * (v0.7.7), not layered onto it — that one answers "is this device online",
 * this one answers "what has this device's signal looked like over time".
 * Different question, different cadence (20 min vs 15 min), different
 * failure model (a status-sync miss just means a stale online/offline flag
 * for another cycle; a signal-history miss is a genuine, permanent gap in a
 * graph) — sharing one service would conflate two independently-evolving
 * concerns.
 *
 * Only devices with a `cpe_parameter_maps` catalog row for `rx_power_dbm`
 * matching their OUI+ProductClass are touched at all — a device whose model
 * has never been catalogued for this metric has nothing to refresh and
 * nothing meaningful to record, so it's skipped entirely (no history row),
 * distinct from a device that IS catalogued but whose refresh genuinely
 * failed (which DOES get a row, with rx_power_dbm null — a real gap the
 * history graph should be able to show, not a silently missing data point).
 *
 * Refresh is TARGETED at just the RX power leaf's own parent object (e.g.
 * `WANDevice.1.X_CT-COM_GponInterfaceConfig`), derived directly from the
 * catalog's own `parameter_path` — narrower than CpeActionService::
 * syncNow()'s "Sync Sekarang" button (which refreshes the whole `WANDevice`
 * subtree, useful for a one-off manual full resync but unnecessarily heavy
 * to repeat automatically for hundreds of devices every 20 minutes).
 *
 * Sending is deliberately STAGGERED, not fired all at once — with 400+ real
 * online CPE in this fleet, an unstaggered burst would spike GenieACS's own
 * CWMP connection-request load in the same instant. Devices are chunked
 * (self::SEND_CHUNK_SIZE) with a short sleep between chunks
 * (self::SEND_CHUNK_DELAY_SECONDS), spreading the actual sends across
 * several minutes; only ONE read-back wait (self::READ_WAIT_SECONDS, the
 * same 90s figure CpeDeviceStatusSyncService's own docblock already
 * established from real measured connection_request latency, 0.7-60s)
 * happens at the very end, after every send — every device gets AT LEAST
 * 90s between its own send and the read, devices sent earlier in the run
 * get considerably more. Worked example at 400 devices, chunk size 5, 3s
 * inter-chunk delay: 80 chunks x 3s = 240s (4 min) to send everything, +90s
 * wait = ~5.5 minutes total wall-clock — comfortably inside the 20-minute
 * schedule interval with wide margin, so routes/console.php also applies
 * ->withoutOverlapping() as a defensive backstop, not because this is
 * expected to run long.
 *
 * The actual read-back reuses CpeParameterResolverService::
 * resolveDeviceSummary() verbatim — the exact same per-vendor path +
 * conversion-formula resolution the CPE detail page itself already uses,
 * so a recorded history value is guaranteed consistent with whatever the
 * page would show if loaded at that same moment. No new parsing/formula
 * logic is written here.
 */
class CpeSignalHistoryService
{
    private const SEND_CHUNK_SIZE = 5;

    private const SEND_CHUNK_DELAY_SECONDS = 3;

    private const READ_WAIT_SECONDS = 90;

    public function __construct(
        private readonly GenieAcsClientService $genieAcsClient,
        private readonly CpeParameterResolverService $resolver,
    ) {}

    /**
     * @return array{recorded: int, failed: int, skipped: int, total_online: int}
     */
    public function syncAll(): array
    {
        $devices = CpeDevice::withoutGlobalScopes()
            ->where('status', CpeDeviceStatus::Online)
            ->whereNotNull('genieacs_device_id')
            ->get();

        $result = ['recorded' => 0, 'failed' => 0, 'skipped' => 0, 'total_online' => $devices->count()];

        if ($devices->isEmpty()) {
            return $result;
        }

        $genieAcsById = $this->fetchGenieAcsDeviceIdentities();
        $catalog = $this->rxPowerCatalog();

        $targets = [];

        foreach ($devices as $device) {
            $identity = $genieAcsById?->get($device->genieacs_device_id);
            $oui = $identity['_deviceId']['_OUI'] ?? null;
            $productClass = $identity['_deviceId']['_ProductClass'] ?? null;

            $map = ($oui !== null && $productClass !== null)
                ? $catalog->get($oui.'|'.$productClass)
                : null;

            if ($map === null) {
                $result['skipped']++;

                continue;
            }

            $targets[] = ['device' => $device, 'objectName' => Str::beforeLast($map->parameter_path, '.')];
        }

        if ($targets === []) {
            return $result;
        }

        $sendFailedDeviceIds = [];

        foreach (array_chunk($targets, self::SEND_CHUNK_SIZE) as $chunk) {
            foreach ($chunk as $entry) {
                if (! $this->sendTargetedRefresh($entry['device'], $entry['objectName'])) {
                    $sendFailedDeviceIds[$entry['device']->id] = true;
                }
            }

            Sleep::for(self::SEND_CHUNK_DELAY_SECONDS)->seconds();
        }

        Sleep::for(self::READ_WAIT_SECONDS)->seconds();

        $recordedAt = now();

        foreach ($targets as $entry) {
            $device = $entry['device'];

            $rxPower = isset($sendFailedDeviceIds[$device->id])
                ? null
                : ($this->resolver->resolveDeviceSummary($device->genieacs_device_id)['rx_power_dbm'] ?? null);

            try {
                CpeSignalHistory::create([
                    'cpe_device_id' => $device->id,
                    'rx_power_dbm' => $rxPower,
                    'recorded_at' => $recordedAt,
                ]);
            } catch (Throwable $e) {
                // A DB-level failure for one row (real example hit while
                // building this: a table-name mismatch bug) must not abort
                // the rest of an already-90s-long batch — log and move on,
                // same resilience posture as sendTargetedRefresh() above.
                Log::warning("CpeSignalHistoryService: gagal menulis cpe_signal_history untuk CpeDevice #{$device->id} — {$e->getMessage()}");
                $result['failed']++;

                continue;
            }

            $rxPower !== null ? $result['recorded']++ : $result['failed']++;
        }

        return $result;
    }

    /**
     * One bulk GenieACS query for every online device's `_deviceId`
     * (OUI/ProductClass) — same "one HTTP call, not N" pattern
     * CpeDeviceStatusSyncService::fetchGenieAcsDevices() already
     * established, reused here for the same reason.
     *
     * @return ?Collection<string, array<string, mixed>> null on a genuine GenieACS query failure
     */
    private function fetchGenieAcsDeviceIdentities(): ?Collection
    {
        try {
            return collect($this->genieAcsClient->queryDevices([], ['_id', '_deviceId']))->keyBy('_id');
        } catch (Throwable $e) {
            Log::warning("CpeSignalHistoryService: gagal query GenieACS untuk daftar OUI/ProductClass — {$e->getMessage()}");

            return null;
        }
    }

    /**
     * @return Collection<string, CpeParameterMap> keyed by "{oui}|{product_class}"
     */
    private function rxPowerCatalog(): Collection
    {
        return CpeParameterMap::query()
            ->where('parameter_key', 'rx_power_dbm')
            ->get()
            ->keyBy(fn (CpeParameterMap $m) => $m->oui.'|'.$m->product_class);
    }

    /**
     * Never sends a root-level or WANDevice-wide refresh — always the exact
     * parent object of the catalog's own rx_power_dbm parameter_path, e.g.
     * `WANDevice.1.X_CT-COM_GponInterfaceConfig` (path minus its final
     * `.RXPower` segment). Returns false only on a genuine GenieACS enqueue
     * failure (GenieAcsClientService::sendTask()'s own ->throw() — a
     * connection_request that simply didn't reach the device yet is NOT an
     * exception here, same distinction CpeActionService/
     * CpeDeviceStatusSyncService already rely on) — never fatal to the rest
     * of the batch.
     */
    private function sendTargetedRefresh(CpeDevice $device, string $objectName): bool
    {
        try {
            $this->genieAcsClient->sendTask(
                $device->genieacs_device_id,
                ['name' => 'refreshObject', 'objectName' => $objectName],
                connectionRequest: true,
            );

            return true;
        } catch (Throwable $e) {
            Log::warning("CpeSignalHistoryService: gagal kirim refreshObject({$objectName}) untuk CpeDevice #{$device->id} — {$e->getMessage()}");

            return false;
        }
    }
}
