<?php

namespace App\Services\Network\Contracts;

use App\Models\Nas;

/**
 * Boundary around the actual MikroTik RouterOS API transport (raw sockets,
 * not HTTP — evilfreelancer/routeros-api-php under the hood via
 * RouterOsApiGateway) so NasService stays testable without a real router:
 * tests bind a fake implementation instead of relying on something like
 * Http::fake(), which doesn't apply here since the client speaks the RouterOS
 * binary API over a socket, not HTTP.
 */
interface RouterOsGateway
{
    /**
     * @return array{online: bool, message: ?string}
     */
    public function ping(Nas $nas): array;

    /**
     * Real ICMP ping (`/ping address=... count=...`) issued FROM $nas's own
     * router toward $targetIp — not a connection attempt from boss-app
     * itself. Built for App\Services\Network\CpeDeviceStatusSyncService:
     * checking a CPE's reachability from boss-app directly over the
     * WireGuard hub-and-spoke tunnel never worked (confirmed empirically —
     * even genieacs-cwmp, which the v0.7.3 routing work specifically
     * targeted, times out reaching a real CPE's TR-069 management IP), but
     * the NAS router itself sits directly on the CPE's own local VLAN with
     * no tunnel involved at all.
     *
     * Requires the RouterOS API user's group to include the `test` policy
     * category (ping falls under `test`, not `write`) — the existing
     * `boss-app-api` group deliberately excludes it (see
     * RouterOsApiGateway::API_USER_POLICY's own docblock); a real router
     * grant is needed before this returns anything but false.
     */
    public function pingHost(Nas $nas, string $targetIp, int $count = 2): bool;

    /**
     * Creates/replaces a dedicated, restricted-policy API user on the
     * router (group `boss-app-api`, idempotently ensured) — v0.6.5.
     * Connects using $connectAsUsername/$connectAsPassword, which is
     * DELIBERATELY separate from $nas's own stored api_username/
     * api_password: the caller passes the NAS owner's real admin
     * credential for the one-time initial provisioning, or the NAS's own
     * current (already-provisioned) API credential for later self-
     * rotation — this method itself doesn't know or care which, it just
     * connects with whatever it's given and creates $newApiUsername/
     * $newApiPassword. Never persists anything — the caller
     * (NasApiUserProvisioningService) is responsible for writing the new
     * credential onto $nas afterward, only on success.
     *
     * @return array{success: bool, message: ?string}
     */
    public function provisionApiUser(
        Nas $nas,
        string $connectAsUsername,
        string $connectAsPassword,
        string $newApiUsername,
        string $newApiPassword,
    ): array;

    /**
     * v0.8.1 fragment+reconcile (App\Console\Commands\VpnSyncRouteFragments)
     * — asks $nas's own router which WireGuard listen port its tunnel is
     * CURRENTLY connected to (`current-endpoint-port` on the matching
     * `/interface/wireguard/peers` entry, found by its own
     * "... NAS {$account->username}" comment — see
     * MikrotikScriptGenerator::wireGuardScript()). This is the ONLY
     * reliable way to know which of the 3 pool nodes a NAS's auto-switch-
     * capable tunnel currently lives on — that decision happens entirely
     * client-side on the router (v0.6.4 auto-switch script), invisible to
     * boss-app any other way. Returns null if the NAS has no matching
     * WireGuard peer, or the query fails for any reason (caller treats
     * null the same as "can't currently be determined" — never guesses).
     */
    public function currentWireguardEndpointPort(Nas $nas, string $peerCommentNeedle): ?int;
}
