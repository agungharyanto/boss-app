<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * v0.12.3 — sama pola App\Exceptions\InvalidInvoiceStatusTransitionException:
 * {success:false, message, data:null, meta:[]} lewat render(), auto-dipakai
 * Laravel's exception handler tanpa try/catch manual di controller.
 * `technician:token` (command) tetap catch ini secara manual (command tidak
 * lewat pipeline HTTP render()).
 */
class TechnicianTokenException extends RuntimeException
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
