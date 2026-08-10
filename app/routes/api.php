<?php

use App\Http\Controllers\Api\V1\CpeDeviceController;
use App\Http\Controllers\Api\V1\CpeParameterMapController;
use App\Http\Controllers\Api\V1\CustomerContactController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerTimelineController;
use App\Http\Controllers\Api\V1\DashboardWidgetSettingController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\LocaleSettingController;
use App\Http\Controllers\Api\V1\NasController;
use App\Http\Controllers\Api\V1\OdpController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\RegistrationController;
use App\Http\Controllers\Api\V1\RemittanceSummaryController;
use App\Http\Controllers\Api\V1\ResellerController;
use App\Http\Controllers\Api\V1\ResellerPackagePricingController;
use App\Http\Controllers\Api\V1\ResellerTaxPolicyController;
use App\Http\Controllers\Api\V1\ResellerUserController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TaxComponentController;
use App\Http\Controllers\Api\V1\TaxLedgerController;
use App\Http\Controllers\Api\V1\TechnicianController;
use App\Http\Controllers\Api\V1\ThemeSettingsController;
use App\Http\Controllers\Api\V1\VpnAccountController;
use App\Http\Controllers\Api\V1\WhatsappGatewaySettingsController;
use App\Http\Controllers\Api\V1\WhatsappMessageLogController;
use App\Http\Controllers\Api\V1\WhatsappMessageTemplateController;
use App\Http\Controllers\Api\V1\WhatsappSessionController;
use App\Http\Controllers\Api\V1\WhatsappWebhookController;
use App\Http\Controllers\Api\V1\WorkOrderController;
use App\Http\Controllers\Api\V1\XenditWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', [HealthController::class, 'index']);

    // Public — no Sanctum (Xendit can't log in), signature verification
    // inside PaymentService::handleWebhook stands in for auth entirely.
    // Rate-limited defensively (Xendit itself won't hammer this, but any
    // public unauthenticated endpoint is a target) — see BOSS-005.
    Route::post('webhooks/xendit', [XenditWebhookController::class, 'handle'])
        ->middleware('throttle:60,1');

    // Public — no Sanctum (the Node whatsapp-gateway service can't log in),
    // HMAC-SHA256 signature verification inside
    // WhatsappSessionService::updateStatusFromWebhook stands in for auth.
    Route::post('whatsapp/webhook/session-status', [WhatsappWebhookController::class, 'sessionStatus'])
        ->middleware('throttle:60,1');

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

            Route::get('invoices/{invoice}/payments', [PaymentController::class, 'index']);
            Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store']);

            Route::get('whatsapp/sessions', [WhatsappSessionController::class, 'index']);
            Route::get('whatsapp/sessions/{session}', [WhatsappSessionController::class, 'show']);
            Route::post('whatsapp/sessions/{session}/refresh-qr', [WhatsappSessionController::class, 'refreshQr']);

            Route::get('whatsapp/templates', [WhatsappMessageTemplateController::class, 'index']);
            Route::put('whatsapp/templates/{eventType}', [WhatsappMessageTemplateController::class, 'update']);
            Route::delete('whatsapp/templates/{eventType}', [WhatsappMessageTemplateController::class, 'resetToDefault']);

            Route::get('whatsapp/message-logs', [WhatsappMessageLogController::class, 'index']);
            Route::post('whatsapp/message-logs/{log}/retry', [WhatsappMessageLogController::class, 'retry']);

            Route::get('work-orders', [WorkOrderController::class, 'index']);
            Route::post('work-orders', [WorkOrderController::class, 'store']);
            Route::get('work-orders/{work_order}', [WorkOrderController::class, 'show']);
            Route::post('subscriptions/{subscription}/work-order', [WorkOrderController::class, 'storeFromSubscription']);
            Route::post('work-orders/{work_order}/verify', [WorkOrderController::class, 'verify']);
            Route::post('work-orders/{work_order}/assign', [WorkOrderController::class, 'assign']);
            Route::post('work-orders/{work_order}/start', [WorkOrderController::class, 'start']);
            Route::post('work-orders/{work_order}/photos', [WorkOrderController::class, 'storePhoto']);
            Route::post('work-orders/{work_order}/devices', [WorkOrderController::class, 'storeDevice']);
            // v0.7.5 — bridge endpoint, separate from storeDevice() above
            // (scan) because SSID/password arrives at a different moment
            // (often a later phone call, not at scan time). See
            // ProvisionWorkOrderDeviceRequest's own docblock.
            Route::patch('work-orders/{work_order}/devices/{device}/provisioning', [WorkOrderController::class, 'provisionDevice']);
            Route::post('work-orders/{work_order}/complete', [WorkOrderController::class, 'complete']);
            Route::post('work-orders/{work_order}/cancel', [WorkOrderController::class, 'cancel']);

            Route::get('odps', [OdpController::class, 'index']);
            Route::post('odps', [OdpController::class, 'store']);
            Route::get('odps/{odp}', [OdpController::class, 'show']);
            Route::put('odps/{odp}', [OdpController::class, 'update']);
            Route::delete('odps/{odp}', [OdpController::class, 'destroy']);

            Route::get('technicians', [TechnicianController::class, 'index']);
            Route::post('technicians', [TechnicianController::class, 'store']);
            Route::get('technicians/{technician}', [TechnicianController::class, 'show']);

            Route::get('nas', [NasController::class, 'index']);
            Route::post('nas', [NasController::class, 'store']);
            Route::get('nas/{nas}', [NasController::class, 'show']);
            Route::put('nas/{nas}', [NasController::class, 'update']);
            Route::delete('nas/{nas}', [NasController::class, 'destroy']);
            Route::post('nas/{nas}/test-connection', [NasController::class, 'testConnection']);
            Route::post('nas/{nas}/disconnect', [NasController::class, 'disconnect']);
            Route::post('nas/{nas}/provision-api-user', [NasController::class, 'provisionApiUser']);

            Route::post('nas/{nas}/vpn-account', [VpnAccountController::class, 'provision']);
            Route::post('vpn-accounts/{vpn_account}/revoke', [VpnAccountController::class, 'revoke']);

            // v0.7.1 GenieACS — read-only for the device ROW itself, no
            // store/update/destroy (binding otomatis lewat
            // CpeBindingService, bukan endpoint create).
            Route::get('cpe-devices', [CpeDeviceController::class, 'index']);
            Route::get('cpe-devices/{cpe_device}', [CpeDeviceController::class, 'show']);

            // v0.7.4 Remote Actions — task "diantre", bukan instan (lihat
            // CLAUDE.md "GenieACS Connection Request Routing (v0.7.3)" untuk
            // kenapa Connection Request belum bisa diandalkan).
            Route::post('cpe-devices/{cpe_device}/actions/reboot', [CpeDeviceController::class, 'reboot']);
            Route::post('cpe-devices/{cpe_device}/actions/wifi', [CpeDeviceController::class, 'setWifi']);
            Route::get('cpe-devices/{cpe_device}/actions', [CpeDeviceController::class, 'actions']);
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
        Route::get('settings/whatsapp-gateway', [WhatsappGatewaySettingsController::class, 'show']);
        Route::put('settings/whatsapp-gateway', [WhatsappGatewaySettingsController::class, 'update']);

        // v0.7.2 — per-vendor TR-069 parameter mapping catalog, platform-level
        // (super_admin-only, see CpeParameterMapPolicy), no reseller.context
        // needed, same posture as tax-components/settings/* above.
        Route::get('cpe-parameter-maps', [CpeParameterMapController::class, 'index']);
        Route::post('cpe-parameter-maps', [CpeParameterMapController::class, 'store']);
        Route::get('cpe-parameter-maps/{cpe_parameter_map}', [CpeParameterMapController::class, 'show']);
        Route::put('cpe-parameter-maps/{cpe_parameter_map}', [CpeParameterMapController::class, 'update']);
        Route::delete('cpe-parameter-maps/{cpe_parameter_map}', [CpeParameterMapController::class, 'destroy']);
        Route::post('cpe-parameter-maps/{cpe_parameter_map}/verify', [CpeParameterMapController::class, 'markVerified']);
        Route::get('cpe-parameter-maps/resolve/{genieacs_device_id}', [CpeParameterMapController::class, 'resolve'])
            ->where('genieacs_device_id', '.*');

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
