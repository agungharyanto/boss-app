<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;

/**
 * Keeps the API response envelope consistent across controllers, matching
 * the {success, message, data, meta} shape established by HealthController.
 */
trait ApiResponds
{
    protected function success(mixed $data = null, string $message = 'OK', array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => $meta,
        ], $status);
    }
}
