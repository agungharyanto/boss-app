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
 * Design (hybrid): a device whose GenieACS-reported `_lastInform` is fresher
 * than `config('services.cpe.online_threshold_minutes')` (default 180 = 3h)
 * is marked online directly — no probe. Only genuinely stale devices get an
 * active probe: fire connection_request for all of them, sleep
 * self::PROBE_WAIT_SECONDS (90s), then re-check `_lastInform`. A device
 * whose `_lastInform` advanced past the probe time is online.
 *
 * **Amendment (2026-09-04) — "offline palsu"**: dulu ambang online cuma
 * 5 menit dan probe gagal LANGSUNG = Offline. Konsekuensi: ONT nyata yang
 * Inform tiap 1-12 jam (banyak vendor default begitu) DAN connection_request-
 * nya gagal (mismatch `cwmp.connectionRequestAuth` / batasan routing tunnel,
 * lihat catatan v0.7.7) salah dicap Offline setiap siklus — sampai admin
 * input ulang SN yang SAMA lewat "Ganti Modem" (`CpeBindingService::
 * bindFromLegacyImport()` set Online tanpa cek kesegaran Inform). Fix:
 * (1) ambang online jauh lebih panjang (3 jam, configurable);
 * (2) probe gagal HANYA men-set Offline kalau Inform terakhir > hard-cutoff
 *     (`config('services.cpe.offline_hard_cutoff_minutes')`, default 1440 =
 *     24 jam) atau tidak pernah ada; di antara dua ambang, probe gagal TIDAK
 *     mengubah status ("jangan bohong offline kalau belum yakin").
 */
class CpeDeviceStatusSyncService
{
    private const PROBE_WAIT_SECONDS = 90;

    /**
     * Inform lebih baru dari ini → langsung Online tanpa probe. BUKAN lagi
     * 5 menit (yang salah cap Offline setiap ONT dengan
     * PeriodicInformInterval panjang) — lihat config/services.php `cpe.*`.
     */
    private function onlineThreshold(): Carbon
    {
        return now()->subMinutes((int) config('services.cpe.online_threshold_minutes', 180));
    }

    /**
     * Hanya kalau Inform terakhir LEBIH LAMA dari ini (dan probe gagal)
     * device benar-benar di-set Offline. Di antara `onlineThreshold()` dan
     * ini: probe gagal → status TIDAK diubah.
     */
    private function offlineHardCutoff(): Carbon
    {
        return now()->subMinutes((int) config('services.cpe.offline_hard_cutoff_minutes', 1440));
    }

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

        $onlineThreshold = $this->onlineThreshold();
        $stale = [];

        foreach ($devices as $device) {
            $genieAcsDevice = $genieAcsById->get($device->genieacs_device_id);

            if ($genieAcsDevice === null) {
                $result['skipped']++;

                continue;
            }

            $lastInform = $this->parseLastInform($genieAcsDevice);

            // Inform-nya masih dalam ambang (default 3 jam) — langsung Online,
            // tidak perlu probe. Ambang panjang ini yang bikin ONT dengan
            // PeriodicInformInterval panjang tidak lagi salah cap Offline.
            if ($lastInform !== null && $lastInform->greaterThan($onlineThreshold)) {
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

        $hardCutoff = $this->offlineHardCutoff();

        foreach ($stale as $entry) {
            $device = $entry['device'];
            $recheckDevice = $recheckById->get($device->genieacs_device_id);
            $newLastInform = $recheckDevice !== null ? $this->parseLastInform($recheckDevice) : null;

            $lastInform = $newLastInform ?? $entry['lastInform'];

            if ($newLastInform !== null && $newLastInform->greaterThan($probeSentAt)) {
                // Probe berhasil — device benar-benar hidup.
                $this->applyStatus($device, CpeDeviceStatus::Online, $lastInform);
                $result['synced']++;
                $result['online']++;

                continue;
            }

            // Probe gagal. HANYA set Offline kalau Inform terakhir sudah
            // benar-benar lama (atau tidak pernah ada). Di rentang antara
            // ambang online (3 jam) dan hard-cutoff (24 jam): status TIDAK
            // diubah — probe gagal ≠ device mati (connection_request bisa
            // gagal karena mismatch cwmp.connectionRequestAuth / routing
            // tunnel, lihat CLAUDE.md v0.7.7), dan ONT bisa Inform tiap
            // beberapa jam saja.
            if ($lastInform === null || $lastInform->lessThan($hardCutoff)) {
                $this->applyStatus($device, CpeDeviceStatus::Offline, $lastInform);
                $result['synced']++;
                $result['offline']++;

                continue;
            }

            // Tak bisa dipastikan — biarkan status apa adanya, tapi tetap
            // segarkan last_inform_at kalau ada nilai baru.
            if ($newLastInform !== null) {
                $this->applyStatus($device, $device->status, $newLastInform);
            }
            $result['synced']++;
            $result['skipped']++;
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
