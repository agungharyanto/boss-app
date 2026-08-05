<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown by CoaService when coa-worker.sh (inside the freeradius container)
 * never picks up a queued request within the poll timeout — usually means
 * the freeradius container itself is unhealthy/restarting. Same
 * self-rendering envelope pattern as NasNotProvisionedException.
 */
class CoaTimeoutException extends RuntimeException
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
