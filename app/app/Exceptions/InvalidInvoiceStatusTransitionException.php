<?php

namespace App\Exceptions;

use App\Enums\InvoiceStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Mirrors App\Exceptions\InvalidStatusTransitionException (Customer status,
 * v0.2.0) — same {success:false, message, data:null, meta:[]} envelope via
 * render(), auto-picked-up by Laravel's exception handler with no manual
 * try/catch needed in controllers.
 */
class InvalidInvoiceStatusTransitionException extends RuntimeException
{
    public function __construct(public readonly InvoiceStatus $from, public readonly InvoiceStatus $to)
    {
        parent::__construct("Tidak bisa mengubah status invoice dari {$from->label()} ke {$to->label()}.");
    }

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
