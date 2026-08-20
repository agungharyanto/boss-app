<?php

use App\Http\Controllers\Api\Internal\CpeDeviceActionController;
use App\Http\Controllers\Api\Internal\CpeDeviceDatatableController;
use App\Http\Controllers\Api\Internal\CpeDeviceDetailController;
use App\Http\Controllers\VpnScriptDownloadController;
use App\Livewire\Billing\InvoiceIndex;
use App\Livewire\Billing\ReconciliationReport;
use App\Livewire\Billing\SubscriptionIndex;
use App\Livewire\Customers\CustomerIndex;
use App\Livewire\Customers\CustomerShow;
use App\Livewire\Customers\RegisterCustomer;
use App\Livewire\Dashboard;
use App\Livewire\Installation\WorkOrderShow;
use App\Livewire\Network\CpeDeviceIndex;
use App\Livewire\Network\CpeDeviceStatusCheck;
use App\Livewire\Network\CpeParameterMapIndex;
use App\Livewire\Network\NasIndex;
use App\Livewire\Network\VpnScriptGenerator;
use App\Livewire\Resellers\PackagePricingIndex;
use App\Livewire\Resellers\ResellerIndex;
use App\Livewire\Resellers\ResellerShow;
use App\Livewire\Settings\PaymentGatewaySettings;
use App\Livewire\Settings\ThemeSettings;
use App\Livewire\Tax\ResellerTaxPolicyIndex;
use App\Livewire\Tax\TaxComponentIndex;
use App\Livewire\Whatsapp\WhatsappGatewayIndex;
use App\Services\LocaleService;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Deliberately unauthenticated — fetched by RouterOS's /tool fetch, which
// carries no session/API credentials. See ScriptDownloadTokenService for why
// a bare high-entropy, single-use, short-TTL token is the security boundary
// here instead. throttle:30,1 is just abuse-rate hygiene, not the real
// protection.
Route::get('/vpn-script-generator/download/{token}.rsc', [VpnScriptDownloadController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('vpn-script-generator.download');

// Public — guests can switch language too, not just logged-in users.
Route::get('/lang/{locale}', function (string $locale, LocaleService $service) {
    abort_unless($service->isSupported($locale), 404);

    session(['locale' => $locale]);

    if (auth()->check()) {
        $service->update(auth()->user(), $locale);
    }

    return redirect()->back();
})->name('lang.switch');

Route::middleware('auth')->name('web.')->group(function () {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');

    // reseller.context: see routes/api.php's identical group for why —
    // Customer/ResellerPackagePricing listings need "which reseller am I"
    // resolved before rendering.
    Route::middleware('reseller.context')->group(function () {
        Route::get('/customers', CustomerIndex::class)->name('customers.index');
        Route::get('/customers/register', RegisterCustomer::class)->name('customers.register');
        Route::get('/customers/{customer}', CustomerShow::class)->name('customers.show');

        Route::get('/reseller-package-pricing', PackagePricingIndex::class)->name('reseller-package-pricing.index');
        Route::get('/reseller-tax-policies', ResellerTaxPolicyIndex::class)->name('reseller-tax-policies.index');
        Route::get('/subscriptions', SubscriptionIndex::class)->name('subscriptions.index');
        Route::get('/invoices', InvoiceIndex::class)->name('invoices.index');
        Route::get('/payment-reconciliation', ReconciliationReport::class)->name('payment-reconciliation.index');
        Route::get('/whatsapp-gateway', WhatsappGatewayIndex::class)->name('whatsapp-gateway.index');
        Route::get('/nas', NasIndex::class)->name('nas.index');
        Route::get('/vpn-script-generator', VpnScriptGenerator::class)->name('vpn-script-generator.index');
        Route::get('/cpe-devices', CpeDeviceIndex::class)->name('cpe-devices.index');
        Route::get('/cpe-devices/status-check', CpeDeviceStatusCheck::class)->name('cpe-devices.status-check');

        // Standalone detail page (2026-08-16, replaces the DataTables
        // child-row expand interaction — see CpeDeviceDetailController's
        // own docblock). Must be registered AFTER /cpe-devices/status-check
        // above so that literal segment doesn't get swallowed by this
        // {cpe_device} wildcard.
        Route::get('/cpe-devices/{cpe_device}', [CpeDeviceDetailController::class, 'page'])->name('cpe-devices.show');

        // v0.7.6-follow-up — support endpoints for the /cpe-devices
        // DataTables list (server-side sort/search/pagination + child-row
        // detail/actions). Deliberately in routes/web.php despite the
        // /api/internal/ URI prefix — these are same-page AJAX calls from
        // an already-authenticated browser SESSION, not Sanctum API token
        // consumers (no config/sanctum.php stateful-domain setup exists in
        // this project), so they need the web middleware group's session
        // auth + CSRF, not routes/api.php's stateless "api" group.
        Route::prefix('api/internal/cpe-devices')->name('cpe-devices.internal.')->group(function () {
            Route::get('/datatable', CpeDeviceDatatableController::class)->name('datatable');
            Route::get('/{cpe_device}/detail', [CpeDeviceDetailController::class, 'show'])->name('detail');
            Route::get('/{cpe_device}/pppoe-password', [CpeDeviceDetailController::class, 'pppoePassword'])->name('pppoe-password');
            Route::post('/{cpe_device}/reboot', [CpeDeviceActionController::class, 'reboot'])->name('reboot');
            Route::post('/{cpe_device}/wifi', [CpeDeviceActionController::class, 'wifi'])->name('wifi');
            Route::post('/{cpe_device}/ssid-enabled', [CpeDeviceActionController::class, 'ssidEnabled'])->name('ssid-enabled');
            Route::post('/{cpe_device}/sync-now', [CpeDeviceActionController::class, 'syncNow'])->name('sync-now');
            Route::post('/{cpe_device}/replace-modem', [CpeDeviceActionController::class, 'replaceModem'])->name('replace-modem');
            Route::delete('/{cpe_device}', [CpeDeviceActionController::class, 'destroy'])->name('destroy');
        });
        Route::get('/work-orders/{work_order}', WorkOrderShow::class)->name('work-orders.show');
    });

    Route::get('/resellers', ResellerIndex::class)->name('resellers.index');
    Route::get('/resellers/{reseller}', ResellerShow::class)->name('resellers.show');

    Route::get('/tax-components', TaxComponentIndex::class)->name('tax-components.index');

    Route::get('/settings/theme', ThemeSettings::class)->name('settings.theme');
    Route::get('/settings/payment-gateway', PaymentGatewaySettings::class)->name('settings.payment-gateway');
    Route::get('/cpe-parameter-maps', CpeParameterMapIndex::class)->name('cpe-parameter-maps.index');
});
