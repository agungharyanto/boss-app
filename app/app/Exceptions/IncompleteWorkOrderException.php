<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown by WorkOrderService::complete() when the required 4 photo types or
 * at least 1 scanned device are missing — same envelope pattern as
 * InvalidWorkOrderStatusTransitionException.
 */
class IncompleteWorkOrderException extends RuntimeException
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
