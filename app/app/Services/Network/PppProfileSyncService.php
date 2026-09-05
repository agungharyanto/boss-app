<?php

namespace App\Services\Network;

use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Services\Network\Contracts\RouterOsGateway;
use App\Support\RouterOsQueuePriority;

/**
 * v0.14.5.4 amendment — ATURAN KERAS 1:1 Grup Profil <-> Profil PPP
 * (dikonfirmasi Agung).
 *
 * The single place a Grup Profil's (type=ppp) `/ppp profile` object is
 * assembled and pushed to RouterOS. BEFORE this amendment there were TWO
 * `/ppp profile` objects per package — one for the Grup Profil ("BOSS App -
 * Network Profile Group #N", bare, no rate-limit) and a SEPARATE one for
 * the Profil PPP ("BOSS App - PPP Package #N", with rate-limit). Since one
 * `/ppp profile` in RouterOS can only carry one `rate-limit`, and a Grup
 * Profil is now hard-limited to ONE active Profil PPP (partial unique index
 * `ppp_packages_active_group_unique`), the two are merged into a SINGLE
 * object — keyed by the Grup Profil's own comment:
 *
 *  - No active package -> bare fallback profile (pool/dns/parent-queue/
 *    local-address only; rate-limit/session-timeout actively CLEARED on the
 *    router, see RouterOsGateway::syncPppProfile()'s docblock). This is the
 *    PPPoE Server's Default Profile — the fallback for any session RADIUS
 *    doesn't hand a specific profile to.
 *  - Active package -> the same profile PLUS that package's rate-limit
 *    (from its BandwidthProfile) + session-timeout (from its Masa Aktif).
 *
 * Both PushNetworkProfileGroupToMikrotikJob (group create/edit/resync) and
 * PushPppPackageToMikrotikJob (package create/edit/delete/resync) call this
 * — "Sync Ulang" from EITHER side converges on the exact same router object.
 *
 * FIX 2 (v0.14.5.4) — ensures the referenced `/ip pool` genuinely exists on
 * the router FIRST (idempotent, lookup by comment) before pushing the
 * profile that references it; a `/ppp profile` referencing a nonexistent
 * pool is rejected outright by RouterOS.
 *
 * Bagian B (v0.14.5.4) — every `/ip pool` NAME sent to the router (as the
 * pool's own name, and as the profile's `remote-address`) goes through
 * CustomerIpPool::routerOsPoolName(), which appends " (pool)" when the pool
 * name collides with a `/ppp profile` name on the same NAS (a real WinBox
 * bug: RouterOS mis-resolves `remote-address` when a pool and a profile
 * share a name).
 */
class PppProfileSyncService
{
    /**
     * @return array{success: bool, message: ?string}
     */
    public function push(RouterOsGateway $gateway, NetworkProfileGroup $group, ?PppPackage $package): array
    {
        $pool = $group->customerIpPool;
        $poolName = $pool->routerOsPoolName();

        // FIX 2 — pastikan `/ip pool` yang direferensikan ADA di router
        // dulu (idempoten, lookup by-comment). Admin sering hapus objek
        // langsung di WinBox; kalau pool hilang, `/ppp/profile/add
        // remote-address=<nama pool>` ditolak RouterOS dan push ini gagal
        // permanen sampai pool dibuat ulang.
        $poolResult = $gateway->syncIpPool(
            $group->nas,
            $pool->mikrotikComment(),
            $poolName,
            "{$pool->range_start}-{$pool->range_end}",
        );

        if (! $poolResult['success']) {
            $poolMessage = 'IP Pool gagal disinkronkan dulu: '.($poolResult['message'] ?? 'Unknown failure');
            $pool->markSyncFailed($poolMessage);

            return ['success' => false, 'message' => $poolMessage];
        }

        $pool->markSynced();

        $dnsServers = array_values(array_filter([$group->dns_primary, $group->dns_secondary]));
        $dnsServer = $dnsServers === [] ? null : implode(',', $dnsServers);

        $rateLimit = null;
        $sessionTimeout = null;

        if ($package !== null && $package->is_active && $package->bandwidthProfile !== null) {
            $bandwidth = $package->bandwidthProfile;
            $rateLimit = RouterOsQueuePriority::toRateLimitString(
                $bandwidth->upload_max,
                $bandwidth->download_max,
                $package->priority,
            );
            $sessionTimeout = $package->routerOsSessionTimeout();
        }

        $profileResult = $gateway->syncPppProfile(
            $group->nas,
            $group->mikrotikComment(),
            $group->name,
            $poolName,
            $dnsServer,
            $group->parent_queue,
            $pool->gateway_ip,
            $rateLimit,
            $sessionTimeout,
        );

        if (! $profileResult['success']) {
            return $profileResult;
        }

        if ($group->interface_name === null || $group->service_name === null) {
            return $profileResult;
        }

        $pppoeResult = $gateway->syncPppoeServer(
            $group->nas,
            $group->mikrotikComment(),
            $group->service_name,
            $group->interface_name,
            $group->name,
        );

        if (! $pppoeResult['success']) {
            return [
                'success' => false,
                'message' => '/ppp profile berhasil, tapi PPPoE Server gagal: '.($pppoeResult['message'] ?? 'Unknown failure'),
            ];
        }

        return $pppoeResult;
    }
}
