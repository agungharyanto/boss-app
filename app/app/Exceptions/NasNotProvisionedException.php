<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown by NasService::testConnection() when mikrotik_ip is still null — the
 * pre-VPN-provisioning state every NAS starts in (v0.6.1 deliberately leaves
 * it nullable, auto-filled by VPN provisioning starting v0.6.2). Same
 * self-rendering envelope pattern as IncompleteWorkOrderException.
 */
class NasNotProvisionedException extends RuntimeException
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
