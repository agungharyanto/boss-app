<?php

namespace App\Services\Network;

use App\Enums\CpeDeviceStatus;
use App\Enums\Tr069Root;
use App\Models\CpeDevice;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Refreshes `status`/`status_changed_at`/`last_inform_at` for every
 * CpeDevice GenieACS already knows about (genieacs_device_id not null) —
 * the gap App\Services\Network\CpeBindingService left open: it only ever
 * WRITES these once, at the moment a device is first bound/reconciled, and
 * never again. `pending_first_connect` devices (genieacs_device_id still
 * null) are deliberately untouched here — that's
 * CpeBindingService::reconcilePending()'s job instead.
 *
 * Second amendment (this session, replacing the RouterOsGateway::pingHost()
 * approach below): the real goal all along was boss-app checking reachability
 * over its OWN tunnel path (boss-app -> WireGuard -> CPE), never delegating
 * to a customer's own router API — a dependency this product can't scale to
 * many ISPs on. Confirmed directly (not assumed) that boss-app itself has
 * NO route/firewall access to a NAS's TR-069 management subnet at all (only
 * genieacs-cwmp/genieacs-nbi have the v0.7.3 firewall exception) — widening
 * that to boss-app was explicitly rejected in favor of reusing genieacs-nbi's
 * own already-working path instead (GenieAcsClientService::sendTask() with
 * connectionRequest=true, the same mechanism CpeActionService already uses).
 *
 * Real, measured behavior of that mechanism (traced through genieacs-cwmp's
 * own Inform log, not assumed from docs) that shapes this class's design:
 * 1. It is NOT synchronous — genieacs-nbi's own internal wait
 *    (CONNECTION_REQUEST_TIMEOUT, default 2000ms, not overridable via a
 *    request query param) is far shorter than real observed CPE response
 *    latency. Measured across 8 confirmed real devices (identified via the
 *    CPE's own `informEvent` containing "6 CONNECTION REQUEST" — the only
 *    reliable proof the push, not a coincidental periodic timer, caused the
 *    Inform): delays ranged 0.7s to 60.0s. So the HTTP 200/202 status
 *    `sendTask()` returns is NOT a usable synchronous online/offline signal
 *    on its own.
 * 2. A device can be demonstrably online (steady `2 PERIODIC` Informs every
 *    60s, confirmed over 3+ minutes) while its connection_request NEVER
 *    once succeeds (never flagged `6 CONNECTION REQUEST`) — likely a
 *    per-device cwmp.connectionRequestAuth credential mismatch, not a
 *    network problem. Treating connection_request success as the sole
 *    online signal would misclassify such a device as offline forever.
 * 3. `getParameterValues` with an EMPTY `parameterNames` array crashes the
 *    genieacs-nbi worker outright (`Error: Missing 'parameterNames'
 *    property`) — confirmed live, worker auto-respawns but every sync run
 *    would otherwise trigger this. sendProbe() must always pass a real,
 *    non-empty parameter path.
 *
 * Design (hybrid, addresses all three findings above): a device whose
 * GenieACS-reported `_lastInform` is already fresher than
 * self::STALE_THRESHOLD_MINUTES is marked online directly — no probe is
 * sent at all, so a device with steady periodic Informs (finding #2) is
 * never at the mercy of a broken connection_request path for it
 * specifically. Only genuinely stale devices get an active probe: fire
 * connection_request for all of them, sleep self::PROBE_WAIT_SECONDS (90s —
 * covers the 60s worst case measured above with margin), then re-check
 * `_lastInform` once more; a device whose `_lastInform` advanced past the
 * moment the probe was sent is online, otherwise offline.
 */
class CpeDeviceStatusSyncService
{
    private const STALE_THRESHOLD_MINUTES = 5;

    private const PROBE_WAIT_SECONDS = 90;

    public function __construct(
        private readonly GenieAcsClientService $genieAcsClient,
    ) {}

    /**
     * @return array{synced: int, online: int, offline: int, skipped: int}
     */
    public function syncAll(): array
    {
        $devices = CpeDevice::withoutGlobalScopes()
            ->whereNotNull('genieacs_device_id')
            ->get();

        $result = ['synced' => 0, 'online' => 0, 'offline' => 0, 'skipped' => 0];

        if ($devices->isEmpty()) {
            return $result;
        }

        $genieAcsById = $this->fetchGenieAcsDevices([
            '_id',
            '_lastInform',
            'InternetGatewayDevice.ManagementServer.ConnectionRequestURL',
        ]);

        if ($genieAcsById === null) {
            $result['skipped'] = $devices->count();

            return $result;
        }

        $staleThreshold = now()->subMinutes(self::STALE_THRESHOLD_MINUTES);
        $stale = [];

        foreach ($devices as $device) {
            $genieAcsDevice = $genieAcsById->get($device->genieacs_device_id);

            if ($genieAcsDevice === null) {
                $result['skipped']++;

                continue;
            }

            $lastInform = $this->parseLastInform($genieAcsDevice);

            // Already fresh (per GenieACS's own record of the device's last
            // real Inform) — mark online directly, no active probe needed.
            if ($lastInform !== null && $lastInform->greaterThan($staleThreshold)) {
                $this->applyStatus($device, CpeDeviceStatus::Online, $lastInform);
                $result['synced']++;
                $result['online']++;

                continue;
            }

            $url = $genieAcsDevice['InternetGatewayDevice']['ManagementServer']['ConnectionRequestURL']['_value'] ?? null;

            if ($url === null) {
                $result['skipped']++;

                continue;
            }

            $stale[] = ['device' => $device, 'lastInform' => $lastInform];
        }

        if ($stale === []) {
            return $result;
        }

        $probeSentAt = now();

        foreach ($stale as $entry) {
            $this->sendProbe($entry['device']);
        }

        Sleep::for(self::PROBE_WAIT_SECONDS)->seconds();

        $recheckById = $this->fetchGenieAcsDevices(['_id', '_lastInform']) ?? collect();

        foreach ($stale as $entry) {
            $device = $entry['device'];
            $recheckDevice = $recheckById->get($device->genieacs_device_id);
            $newLastInform = $recheckDevice !== null ? $this->parseLastInform($recheckDevice) : null;

            $reachable = $newLastInform !== null && $newLastInform->greaterThan($probeSentAt);
            $status = $reachable ? CpeDeviceStatus::Online : CpeDeviceStatus::Offline;

            $this->applyStatus($device, $status, $newLastInform ?? $entry['lastInform']);
            $result['synced']++;
            $status === CpeDeviceStatus::Online ? $result['online']++ : $result['offline']++;
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $projection
     * @return ?Collection<string, array<string, mixed>> null on a genuine GenieACS query failure
     */
    private function fetchGenieAcsDevices(array $projection): ?Collection
    {
        try {
            return collect($this->genieAcsClient->queryDevices([], $projection))->keyBy('_id');
        } catch (Throwable $e) {
            Log::warning("CpeDeviceStatusSyncService: gagal query GenieACS — {$e->getMessage()}");

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $genieAcsDevice
     */
    private function parseLastInform(array $genieAcsDevice): ?Carbon
    {
        $raw = $genieAcsDevice['_lastInform'] ?? null;

        return $raw !== null ? Carbon::parse($raw) : null;
    }

    /**
     * Never sends an empty `parameterNames` array — see this class's own
     * docblock for the real genieacs-nbi worker crash that causes.
     * `DeviceInfo.SerialNumber` is a required TR-069 field, present
     * regardless of vendor, making it a safe, harmless probe target.
     */
    private function sendProbe(CpeDevice $device): void
    {
        $root = $device->tr069_root ?? Tr069Root::InternetGatewayDevice;

        try {
            $this->genieAcsClient->sendTask(
                $device->genieacs_device_id,
                ['name' => 'getParameterValues', 'parameterNames' => ["{$root->value}.DeviceInfo.SerialNumber"]],
                connectionRequest: true,
            );
        } catch (Throwable $e) {
            // Enqueue failure is logged but never fatal to the run — a
            // device whose probe couldn't even be sent simply won't show a
            // fresher last_inform_at at recheck time, same outcome as a
            // probe that sent fine but the device never responded to.
            Log::warning("CpeDeviceStatusSyncService: gagal kirim connection_request probe untuk {$device->genieacs_device_id} — {$e->getMessage()}");
        }
    }

    private function applyStatus(CpeDevice $device, CpeDeviceStatus $status, ?Carbon $lastInform): void
    {
        $attributes = ['status' => $status];

        if ($lastInform !== null) {
            $attributes['last_inform_at'] = $lastInform;
        }

        // Only stamp status_changed_at on a genuine transition — never on
        // every sync run, or "Online Duration" in the UI would always just
        // read "a few minutes" regardless of how long the device has
        // actually been up.
        if ($device->status !== $status) {
            $attributes['status_changed_at'] = now();
        }

        $device->update($attributes);
    }
}
