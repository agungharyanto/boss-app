<?php

namespace App\Exceptions;

use App\Enums\CustomerStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class InvalidStatusTransitionException extends RuntimeException
{
    public function __construct(public readonly CustomerStatus $from, public readonly CustomerStatus $to)
    {
        parent::__construct("Tidak bisa mengubah status dari {$from->label()} ke {$to->label()}.");
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
