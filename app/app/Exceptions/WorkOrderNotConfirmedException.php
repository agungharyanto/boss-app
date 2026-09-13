<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * v0.12.7 Langkah 3 — thrown by WorkOrderService::complete() when
 * `work_orders.technician_confirmed_at` is still null. Same envelope
 * pattern as IncompleteWorkOrderException/InvalidWorkOrderStatusTransitionException
 * — render() picked up automatically by Laravel's exception handler, no
 * manual try/catch needed in the controller.
 */
class WorkOrderNotConfirmedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Konfirmasi WhatsApp diperlukan sebelum instalasi bisa diselesaikan.');
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
