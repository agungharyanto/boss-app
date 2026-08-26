<?php

use App\Http\Controllers\Api\Internal\CpeDeviceActionController;
use App\Http\Controllers\Api\Internal\CpeDeviceDatatableController;
use App\Http\Controllers\Api\Internal\CpeDeviceDetailController;
use App\Http\Controllers\Api\Internal\OltDeviceDatatableController;
use App\Http\Controllers\Auth\ReferrerLoginController;
use App\Http\Controllers\VpnScriptDownloadController;
use App\Http\Middleware\EnsureAdminPanelAccess;
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
use App\Livewire\Network\MonitoringIndex;
use App\Livewire\Network\NasIndex;
use App\Livewire\Network\OltDeviceIndex;
use App\Livewire\Network\VpnScriptGenerator;
use App\Livewire\Referrers\ReferrerIndex;
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

// v0.9.2 — replaces Laravel's own scaffold default (bare "welcome" view,
// never actually replaced since v0.1.0). Guest -> Fortify's /login. Logged
// in -> branches by the EXACT same rule EnsureAdminPanelAccess enforces
// (see that class's own userHasAccess() docblock for why this must reuse
// the middleware's own check rather than a separately-computed one) —
// admin-eligible goes to /dashboard, a pure Referrer-portal account goes
// straight to /referrer-portal instead of /dashboard, so it never bounces
// through a guaranteed 403 first.
Route::get('/', function () {
    if (! auth()->check()) {
        return redirect()->route('login');
    }

    if (EnsureAdminPanelAccess::userHasAccess(auth()->user())) {
        return redirect()->route('web.dashboard');
    }

    return redirect()->route('web.referrer-portal.dashboard');
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

// v0.9.2 — Referrer portal login, deliberately separate from Fortify's own
// /login (hard-wired to email as the username field). Phone + password
// instead, same 'web' guard/session as the admin panel — see
// ReferrerLoginController's own docblock.
Route::middleware('guest')->group(function () {
    Route::get('/referrer/login', [ReferrerLoginController::class, 'show'])->name('referrer.login');
    Route::post('/referrer/login', [ReferrerLoginController::class, 'login'])
        ->middleware('throttle:6,1')
        ->name('referrer.login.attempt');
});

// v0.9.2 — admin.panel closes the "no middleware blocks cross-persona
// access" gap: only a User holding ANY Spatie role (every genuine staff
// account has exactly one) may reach this whole group. A pure Referrer-
// portal account (no Spatie role at all, see ReferrerService::
// attachNewLoginAccount()) is refused here — see EnsureAdminPanelAccess's
// own docblock for why this checks "any role" rather than a hardcoded
// Administrator/superadmin-only list, which would have locked out every
// other existing staff role (noc/customer_service/teknisi/billing/
// sales_internal/sales_freelance/finance).
Route::middleware(['auth', 'admin.panel'])->name('web.')->group(function () {
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
        Route::get('/olt-devices', OltDeviceIndex::class)->name('olt-devices.index');
        Route::get('api/internal/olt-devices/datatable', OltDeviceDatatableController::class)->name('olt-devices.internal.datatable');
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

    // v0.9.2 — CRUD Referrer (admin-side), same posture as /resellers above
    // (tenant-level, no reseller.context needed).
    Route::get('/referrers', ReferrerIndex::class)->name('referrers.index');

    Route::get('/tax-components', TaxComponentIndex::class)->name('tax-components.index');

    Route::get('/settings/theme', ThemeSettings::class)->name('settings.theme');
    Route::get('/settings/payment-gateway', PaymentGatewaySettings::class)->name('settings.payment-gateway');
    Route::get('/cpe-parameter-maps', CpeParameterMapIndex::class)->name('cpe-parameter-maps.index');

    // v0.8.2 — platform-level, same posture as /cpe-parameter-maps above:
    // LibreNMS monitors the ISP's own infra (router + OLTs), not per-
    // tenant/reseller data, so this deliberately sits outside the
    // reseller.context group.
    Route::get('/monitoring', MonitoringIndex::class)->name('monitoring.index');
});

// v0.9.2 — Referrer self-service portal, deliberately its own route group
// (never nested inside the admin.panel-protected group above) — a pure
// Referrer-portal account has no Spatie role at all and would be refused by
// admin.panel; referrer.portal is the mirror-image gate for this group (see
// EnsureReferrerPortalAccess's own docblock).
Route::middleware(['auth', 'referrer.portal'])->name('web.referrer-portal.')->group(function () {
    Route::get('/referrer-portal', App\Livewire\ReferrerPortal\Dashboard::class)->name('dashboard');
});

// Deliberately NOT inside the referrer.portal-gated group above (nor
// Fortify's own shared POST /logout — see ReferrerLoginController::
// logout()'s own docblock for why) — just 'auth', so logging out still
// works even in the edge case where the referrer.portal check itself
// would otherwise fail.
Route::post('/referrer/logout', [ReferrerLoginController::class, 'logout'])
    ->middleware('auth')
    ->name('referrer.logout');
