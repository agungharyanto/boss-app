<?php

namespace App\Services\Network;

use App\Models\CpeBindingRejection;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\LegacyMacCustomerMap;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Continuous (scheduled, see App\Console\Commands\AutoMatchLegacyDevices)
 * counterpart to the one-shot 28-device import — most of the 561 imported
 * MixRadius customers don't have a GenieACS device visible yet, so binding
 * can't be a single batch step. Every run re-scans every GenieACS device
 * that has no cpe_devices row at all yet (regardless of status) and tries
 * to match it against legacy_mac_customer_map.
 *
 * **Matching algorithm, confirmed empirically against the already-verified
 * 28-device batch before being trusted here** (27/28 exact-reproduced the
 * pre-existing match_confidence tags; the 28th only diverged because of a
 * duplicate-phone artifact in the validation script itself, not the
 * algorithm): take the device's own TR-069 SerialNumber, strip the leading
 * vendor letter prefix (e.g. "ZICG"/"CIOT"), and compare the LAST 6 HEX
 * CHARACTERS of what remains against the last 6 hex characters of each
 * candidate MAC address (colons stripped). This is NOT the same MAC as
 * GenieACS's own reported OUI/identity — legacy_mac_customer_map holds the
 * PPPoE/RADIUS session MAC from `radacct`, a different physical interface
 * on the same ONT. Distance 0 = exact, 1 = close_1, 2 = close_2 (anything
 * further is not considered a match at all) — the SAME three confidence
 * tiers, and the SAME distance thresholds, already used for the original
 * 28.
 */
class LegacyDeviceMatcherService
{
    private const TENANT_NAME = 'ISP Demo';

    private const MAX_HEX_DISTANCE = 2;

    public function __construct(
        private readonly GenieAcsClientService $genieAcsClient,
        private readonly CpeBindingService $cpeBindingService,
    ) {}

    public function matchAndBind(): int
    {
        $tenant = Tenant::where('name', self::TENANT_NAME)->first();

        if ($tenant === null) {
            return 0;
        }

        $alreadyBoundGenieAcsIds = CpeDevice::withoutGlobalScopes()
            ->whereNotNull('genieacs_device_id')
            ->pluck('genieacs_device_id')
            ->all();

        $macMap = LegacyMacCustomerMap::all();

        if ($macMap->isEmpty()) {
            return 0;
        }

        $bound = 0;

        foreach ($this->genieAcsClient->queryDevices([]) as $device) {
            $genieAcsId = $device['_id'] ?? null;

            if ($genieAcsId === null || in_array($genieAcsId, $alreadyBoundGenieAcsIds, true)) {
                continue;
            }

            if (! $this->looksLikeRealOntDevice($device)) {
                continue;
            }

            $serialNumber = $device['_deviceId']['_SerialNumber'] ?? null;
            $tail = $serialNumber !== null ? $this->hexTail($serialNumber) : null;

            if ($tail === null) {
                continue;
            }

            $match = $this->findBestMacMatch($tail, $macMap);

            if ($match === null) {
                continue;
            }

            [$mapRow, $confidence] = $match;

            $customer = Customer::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('legacy_username', $mapRow->legacy_username)
                ->first();

            if ($customer === null) {
                continue;
            }

            // An admin explicitly unbound this exact (device, customer)
            // pair before via the "Remove" button — never re-create the
            // same wrong match, even though the hex-tail heuristic would
            // happily match it again every 15 minutes otherwise.
            $rejected = CpeBindingRejection::where('genieacs_device_id', $genieAcsId)
                ->where('customer_id', $customer->id)
                ->exists();

            if ($rejected) {
                continue;
            }

            try {
                $this->cpeBindingService->bindFromLegacyImport($customer, $serialNumber, $confidence);
                $bound++;
            } catch (Throwable $e) {
                Log::warning("LegacyDeviceMatcherService: gagal bind device {$genieAcsId} — {$e->getMessage()}");
            }
        }

        return $bound;
    }

    /**
     * Excludes GenieACS's own internal discovery/probe entries (seen for
     * real in this environment: `_OUI` "DISCOVERYSERVICE" and "000000") —
     * neither is a genuine 6-hex-digit vendor OUI, so they'd never
     * legitimately match a real customer's MAC and are filtered before
     * even attempting the hex comparison.
     *
     * @param  array<string, mixed>  $device
     */
    private function looksLikeRealOntDevice(array $device): bool
    {
        $oui = $device['_deviceId']['_OUI'] ?? null;

        return is_string($oui)
            && preg_match('/^[0-9A-Fa-f]{6}$/', $oui) === 1
            && strtoupper($oui) !== '000000';
    }

    private function hexTail(string $serialNumber): ?string
    {
        $hexPart = preg_replace('/^[A-Za-z]+/', '', $serialNumber);

        if (strlen($hexPart) < 6 || preg_match('/^[0-9A-Fa-f]{6}$/', substr($hexPart, -6)) !== 1) {
            return null;
        }

        return strtoupper(substr($hexPart, -6));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, LegacyMacCustomerMap>  $macMap
     * @return array{0: LegacyMacCustomerMap, 1: string}|null
     */
    private function findBestMacMatch(string $tail, $macMap): ?array
    {
        $best = null;
        $bestDistance = null;

        foreach ($macMap as $row) {
            $macTail = $this->macTail($row->mac_address);

            if ($macTail === null) {
                continue;
            }

            $distance = $this->hexDistance($tail, $macTail);

            if ($distance > self::MAX_HEX_DISTANCE) {
                continue;
            }

            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $row;
            }
        }

        if ($best === null || $bestDistance === null) {
            return null;
        }

        $confidence = match ($bestDistance) {
            0 => 'exact',
            1 => 'close_1',
            2 => 'close_2',
        };

        return [$best, $confidence];
    }

    private function macTail(string $macAddress): ?string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $macAddress));

        if (strlen($hex) < 6) {
            return null;
        }

        return substr($hex, -6);
    }

    private function hexDistance(string $a, string $b): int
    {
        $distance = 0;

        for ($i = 0; $i < 6; $i++) {
            if ($a[$i] !== $b[$i]) {
                $distance++;
            }
        }

        return $distance;
    }
}
