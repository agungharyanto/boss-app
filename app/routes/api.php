<?php

use App\Http\Controllers\Api\V1\CustomerContactController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerTimelineController;
use App\Http\Controllers\Api\V1\DashboardWidgetSettingController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\LocaleSettingController;
use App\Http\Controllers\Api\V1\RegistrationController;
use App\Http\Controllers\Api\V1\RemittanceSummaryController;
use App\Http\Controllers\Api\V1\ResellerController;
use App\Http\Controllers\Api\V1\ResellerPackagePricingController;
use App\Http\Controllers\Api\V1\ResellerTaxPolicyController;
use App\Http\Controllers\Api\V1\ResellerUserController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TaxComponentController;
use App\Http\Controllers\Api\V1\TaxLedgerController;
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

            Route::get('reseller-tax-policies', [ResellerTaxPolicyController::class, 'index']);
            Route::post('reseller-tax-policies', [ResellerTaxPolicyController::class, 'store']);
            Route::get('reseller-tax-policies/{reseller_tax_policy}', [ResellerTaxPolicyController::class, 'show']);
            Route::put('reseller-tax-policies/{reseller_tax_policy}', [ResellerTaxPolicyController::class, 'update']);

            Route::get('subscriptions', [SubscriptionController::class, 'index']);
            Route::post('subscriptions', [SubscriptionController::class, 'store']);
            Route::get('subscriptions/{subscription}', [SubscriptionController::class, 'show']);
            Route::patch('subscriptions/{subscription}/suspend', [SubscriptionController::class, 'suspend']);
            Route::patch('subscriptions/{subscription}/reactivate', [SubscriptionController::class, 'reactivate']);
            Route::patch('subscriptions/{subscription}/cancel', [SubscriptionController::class, 'cancel']);

            Route::get('invoices', [InvoiceController::class, 'index']);
            Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
            Route::post('invoices/generate', [InvoiceController::class, 'generate']);
            Route::patch('invoices/{invoice}/pending', [InvoiceController::class, 'markPending']);
            Route::patch('invoices/{invoice}/paid', [InvoiceController::class, 'markPaid']);
            Route::patch('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel']);
        });

        // Admin-only tax engine catalog/reporting — no reseller.context needed.
        Route::get('tax-components', [TaxComponentController::class, 'index']);
        Route::post('tax-components', [TaxComponentController::class, 'store']);
        Route::get('tax-components/{tax_component}', [TaxComponentController::class, 'show']);
        Route::put('tax-components/{tax_component}', [TaxComponentController::class, 'update']);
        Route::post('tax-components/{tax_component}/update-rate', [TaxComponentController::class, 'updateRate']);

        Route::get('tax-ledger', [TaxLedgerController::class, 'index']);

        Route::get('remittance-summary', [RemittanceSummaryController::class, 'index']);
        Route::post('remittance-summary/generate', [RemittanceSummaryController::class, 'generate']);

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
