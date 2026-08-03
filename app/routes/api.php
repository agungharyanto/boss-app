<?php

use App\Http\Controllers\Api\V1\CustomerContactController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerTimelineController;
use App\Http\Controllers\Api\V1\DashboardWidgetSettingController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\LocaleSettingController;
use App\Http\Controllers\Api\V1\RegistrationController;
use App\Http\Controllers\Api\V1\ThemeSettingsController;
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

        Route::apiResource('customers', CustomerController::class)->except(['destroy']);
        Route::patch('customers/{customer}/status', [CustomerController::class, 'updateStatus']);
        Route::apiResource('customers.contacts', CustomerContactController::class);
        Route::get('customers/{customer}/timeline', [CustomerTimelineController::class, 'index']);

        Route::post('registrations', [RegistrationController::class, 'store']);
        Route::get('referrals', [RegistrationController::class, 'referrals']);

        Route::get('settings/theme', [ThemeSettingsController::class, 'show']);
        Route::put('settings/theme', [ThemeSettingsController::class, 'update']);
        Route::get('settings/locale', [LocaleSettingController::class, 'show']);
        Route::put('settings/locale', [LocaleSettingController::class, 'update']);
        Route::get('settings/dashboard-widgets', [DashboardWidgetSettingController::class, 'show']);
        Route::put('settings/dashboard-widgets', [DashboardWidgetSettingController::class, 'update']);
    });
});
