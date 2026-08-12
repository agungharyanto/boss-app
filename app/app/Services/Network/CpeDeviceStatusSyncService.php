<?php

namespace App\Services\Network;

use App\Enums\CpeDeviceStatus;
use App\Models\CpeDevice;
use App\Models\Nas;
use App\Services\Network\Contracts\RouterOsGateway;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
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
 * Amendment (v0.7.6-follow-up, second cut): a direct TCP connect from
 * boss-app to a CPE's own management IP was tried first and never worked —
 * confirmed empirically that even genieacs-cwmp (which the v0.7.3 WireGuard
 * routing work specifically targeted) times out reaching a real CPE, so
 * treating that as "offline" would have been wrong for every single device,
 * a regression from the old (also wrong, but differently wrong)
 * last_inform_at-threshold approach. Status is now determined by asking
 * `test-x86-bajastu` — the one NAS router that sits directly on the CPE's
 * own local VLAN with no tunnel involved — to ICMP-ping the device
 * (RouterOsGateway::pingHost()) on our behalf, since that path is
 * guaranteed reachable while boss-app's own path to the CPE is not.
 * `last_inform_at` is still synced from GenieACS as auxiliary data, just no
 * longer what `status` is computed from.
 */
class CpeDeviceStatusSyncService
{
    private const VANTAGE_NAS_NAME = 'test-x86-bajastu';

    private const PING_COUNT = 2;

    public function __construct(
        private readonly GenieAcsClientService $genieAcsClient,
        private readonly RouterOsGateway $routerOsGateway,
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

        $nas = Nas::withoutGlobalScopes()->where('name', self::VANTAGE_NAS_NAME)->first();

        if ($nas === null) {
            Log::warning('CpeDeviceStatusSyncService: NAS vantage point "'.self::VANTAGE_NAS_NAME.'" tidak ditemukan.');

            $result['skipped'] = $devices->count();

            return $result;
        }

        try {
            $genieAcsDevices = $this->genieAcsClient->queryDevices([], [
                '_id',
                '_lastInform',
                'InternetGatewayDevice.ManagementServer.ConnectionRequestURL',
            ]);
        } catch (Throwable $e) {
            Log::warning("CpeDeviceStatusSyncService: gagal query GenieACS — {$e->getMessage()}");

            $result['skipped'] = $devices->count();

            return $result;
        }

        $genieAcsById = collect($genieAcsDevices)->keyBy('_id');

        foreach ($devices as $device) {
            $genieAcsDevice = $genieAcsById->get($device->genieacs_device_id);

            if ($genieAcsDevice === null) {
                $result['skipped']++;

                continue;
            }

            $url = $genieAcsDevice['InternetGatewayDevice']['ManagementServer']['ConnectionRequestURL']['_value'] ?? null;
            $ip = $url !== null ? (parse_url($url)['host'] ?? null) : null;

            if ($ip === null) {
                $result['skipped']++;

                continue;
            }

            $reachable = $this->routerOsGateway->pingHost($nas, $ip, self::PING_COUNT);
            $status = $reachable ? CpeDeviceStatus::Online : CpeDeviceStatus::Offline;

            $attributes = ['status' => $status];

            if (isset($genieAcsDevice['_lastInform'])) {
                $attributes['last_inform_at'] = Carbon::parse($genieAcsDevice['_lastInform']);
            }

            // Only stamp status_changed_at on a genuine transition — never
            // on every sync run, or "Online Duration" in the UI would
            // always just read "a few minutes" regardless of how long the
            // device has actually been up.
            if ($device->status !== $status) {
                $attributes['status_changed_at'] = now();
            }

            $device->update($attributes);

            $result['synced']++;
            $status === CpeDeviceStatus::Online ? $result['online']++ : $result['offline']++;
        }

        return $result;
    }
}
