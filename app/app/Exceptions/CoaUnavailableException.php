<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown by CoaService when a NAS has no active OpenVPN/WireGuard account
 * to target (L2TP/IPsec deliberately unsupported — known limitation, see
 * CLAUDE.md). Same self-rendering envelope pattern as NasNotProvisionedException.
 */
class CoaUnavailableException extends RuntimeException
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
