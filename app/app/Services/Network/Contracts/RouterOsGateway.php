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
}
