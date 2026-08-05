<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown by NasApiUserProvisioningService when the router rejects the
 * admin credential or the user/group provisioning commands fail. Same
 * self-rendering envelope pattern as NasNotProvisionedException.
 */
class NasApiUserProvisioningException extends RuntimeException
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
