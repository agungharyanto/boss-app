<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown by NasPortAllocatorService when the auth/acct/coa port range
 * (18120 upward, see the nas_port_allocator_state migration) is exhausted.
 * Same self-rendering envelope pattern as NasNotProvisionedException.
 */
class NasPortPoolExhaustedException extends RuntimeException
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
