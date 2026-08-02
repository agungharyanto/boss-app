<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', [HealthController::class, 'index']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', function () {
            return response()->json([
                'success' => true,
                'message' => 'Authenticated user',
                'data' => request()->user(),
                'meta' => [],
            ]);
        });
    });
});
