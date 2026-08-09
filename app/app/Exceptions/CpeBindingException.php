<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown by CpeBindingService::bindFromWorkOrder() when the work order has
 * no scanned device at all to bind — same envelope pattern as
 * IncompleteWorkOrderException. In practice WorkOrderService::complete()
 * already guarantees at least 1 device before this is ever reached, so this
 * is a defensive guard, not an expected real-world path.
 */
class CpeBindingException extends RuntimeException
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
