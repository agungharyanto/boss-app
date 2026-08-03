<?php

use App\Http\Controllers\Api\V1\CustomerContactController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerTimelineController;
use App\Http\Controllers\Api\V1\DashboardWidgetSettingController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\LocaleSettingController;
use App\Http\Controllers\Api\V1\RegistrationController;
use App\Http\Controllers\Api\V1\ResellerController;
use App\Http\Controllers\Api\V1\ResellerPackagePricingController;
use App\Http\Controllers\Api\V1\ResellerUserController;
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

        // reseller.context resolves "which reseller am I operating as" (null
        // for ISP admins/internal staff) before these routes run, so
        // Customer/ResellerPackagePricing's BelongsToResellerScope trait can
        // narrow queries automatically — see App\Support\ResellerContext.
        Route::middleware('reseller.context')->group(function () {
            Route::apiResource('customers', CustomerController::class)->except(['destroy']);
            Route::patch('customers/{customer}/status', [CustomerController::class, 'updateStatus']);
            Route::apiResource('customers.contacts', CustomerContactController::class);
            Route::get('customers/{customer}/timeline', [CustomerTimelineController::class, 'index']);

            Route::get('reseller-package-pricing', [ResellerPackagePricingController::class, 'index']);
            Route::post('reseller-package-pricing', [ResellerPackagePricingController::class, 'store']);
            Route::get('reseller-package-pricing/{pricing}', [ResellerPackagePricingController::class, 'show']);
            Route::put('reseller-package-pricing/{pricing}', [ResellerPackagePricingController::class, 'update']);
            Route::delete('reseller-package-pricing/{pricing}', [ResellerPackagePricingController::class, 'destroy']);
        });

        Route::post('registrations', [RegistrationController::class, 'store']);
        Route::get('referrals', [RegistrationController::class, 'referrals']);

        Route::get('settings/theme', [ThemeSettingsController::class, 'show']);
        Route::put('settings/theme', [ThemeSettingsController::class, 'update']);
        Route::get('settings/locale', [LocaleSettingController::class, 'show']);
        Route::put('settings/locale', [LocaleSettingController::class, 'update']);
        Route::get('settings/dashboard-widgets', [DashboardWidgetSettingController::class, 'show']);
        Route::put('settings/dashboard-widgets', [DashboardWidgetSettingController::class, 'update']);

        // Admin-only reseller management — no reseller.context needed here,
        // ISP admins always act with an explicit {reseller} route param.
        Route::get('resellers', [ResellerController::class, 'index']);
        Route::post('resellers', [ResellerController::class, 'store']);
        Route::get('resellers/{reseller}', [ResellerController::class, 'show']);
        Route::put('resellers/{reseller}', [ResellerController::class, 'update']);
        Route::delete('resellers/{reseller}', [ResellerController::class, 'destroy']);

        Route::get('resellers/{reseller}/users', [ResellerUserController::class, 'index']);
        Route::post('resellers/{reseller}/users', [ResellerUserController::class, 'store']);
        Route::delete('resellers/{reseller}/users/{user}', [ResellerUserController::class, 'destroy']);
    });
});
