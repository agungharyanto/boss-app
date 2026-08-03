<?php

namespace App\Exceptions;

use App\Enums\WorkOrderStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Mirrors App\Exceptions\InvalidInvoiceStatusTransitionException — same
 * {success:false, message, data:null, meta:[]} envelope via render(),
 * auto-picked-up by Laravel's exception handler with no manual try/catch
 * needed in controllers.
 */
class InvalidWorkOrderStatusTransitionException extends RuntimeException
{
    public function __construct(public readonly WorkOrderStatus $from, public readonly WorkOrderStatus $to)
    {
        parent::__construct("Tidak bisa mengubah status work order dari {$from->label()} ke {$to->label()}.");
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
