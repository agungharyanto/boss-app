<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown by VpnProvisioningService::provision() when a VpnServer's
 * vpn_ip_pool has no row left with status Available — same self-rendering
 * envelope pattern as NasNotProvisionedException.
 */
class VpnIpPoolExhaustedException extends RuntimeException
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
