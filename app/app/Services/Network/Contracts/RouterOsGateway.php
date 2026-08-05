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
}
