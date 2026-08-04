<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown by VpnProvisioningService when the underlying `easyrsa` process
 * (build-client-full / revoke / gen-crl) fails — e.g. the shared PKI volume
 * hasn't been bootstrapped yet by the openvpn container's entrypoint, or a
 * duplicate Common Name. Same self-rendering envelope pattern as
 * NasNotProvisionedException.
 */
class VpnProvisioningException extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'data' => null,
            'meta' => [],
        ], 422);
    }
}
