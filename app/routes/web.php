<?php

use App\Http\Controllers\Api\Internal\CpeDeviceActionController;
use App\Http\Controllers\Api\Internal\CpeDeviceDatatableController;
use App\Http\Controllers\Api\Internal\CpeDeviceDetailController;
use App\Http\Controllers\Api\Internal\OltDeviceDatatableController;
use App\Http\Controllers\Auth\ReferrerLoginController;
use App\Http\Controllers\FiberNodePhotoController;
use App\Http\Controllers\VpnScriptDownloadController;
use App\Http\Middleware\EnsureAdminPanelAccess;
use App\Livewire\Auth\ReferrerForgotPassword;
use App\Livewire\Billing\InvoiceIndex;
use App\Livewire\Billing\ReconciliationReport;
use App\Livewire\Billing\SubscriptionIndex;
use App\Livewire\Commission\CommissionRateIndex;
use App\Livewire\Commission\TitipMasukIndex;
use App\Livewire\Customers\CustomerCoordinateFill;
use App\Livewire\Customers\CustomerIndex;
use App\Livewire\Customers\CustomerShow;
use App\Livewire\Customers\RegisterCustomer;
use App\Livewire\Dashboard;
use App\Livewire\Installation\OdpEdit;
use App\Livewire\Installation\WorkOrderShow;
use App\Livewire\Network\BandwidthProfileIndex;
use App\Livewire\Network\CapacityReport;
use App\Livewire\Network\CpeDeviceIndex;
use App\Livewire\Network\CpeDeviceStatusCheck;
use App\Livewire\Network\CpeParameterMapIndex;
use App\Livewire\Network\CustomerIpPoolIndex;
use App\Livewire\Network\FiberCableForm;
use App\Livewire\Network\FiberNodeDetail;
use App\Livewire\Network\FiberNodeForm;
use App\Livewire\Network\FiberNodeIndex;
use App\Livewire\Network\FiberTopologyMap;
use App\Livewire\Network\HotspotPackageIndex;
use App\Livewire\Network\MonitoringIndex;
use App\Livewire\Network\NasIndex;
use App\Livewire\Network\NetworkProfileGroupIndex;
use App\Livewire\Network\OdpRouteCheck;
use App\Livewire\Network\OltDeviceIndex;
use App\Livewire\Network\PppPackageIndex;
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

    // v0.9.6 — "Lupa Password": Livewire multi-tahap (Nomor HP → OTP
    // WhatsApp → password baru). Reuse ReferrerActionOtpService (scope
    // "password_reset:{id}"). Rate limit kirim OTP ada di service itu.
    Route::get('/referrer/forgot-password', ReferrerForgotPassword::class)
        ->middleware('throttle:20,1')
        ->name('referrer.password.request');
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
        // v0.16.0 Langkah 12 — manual coordinate bind. BEFORE the
        // /customers/{customer} wildcard so the literal segment isn't swallowed.
        Route::get('/customers/lengkapi-koordinat', CustomerCoordinateFill::class)->name('customers.coordinates');
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

    // v0.9.3 — Commission Rate Settings, same posture as /referrers above
    // (tenant-level, no reseller.context needed). Halaman ini me-list
    // SEMUA PppPackage; rate diisi/diedit inline per paket.
    Route::get('/commission-rates', CommissionRateIndex::class)->name('commission-rates.index');

    // v0.9.6 — "Titip Masuk": daftar kerja operasional entri komisi Titip
    // (dibuat Referrer lewat portal). Read-only, tier-admin-only
    // (commission_ledger.view, CommissionLedgerPolicy).
    Route::get('/titip-masuk', TitipMasukIndex::class)->name('titip-masuk.index');

    // v0.14.1 — fondasi cluster "Profil Paket", same posture as /referrers
    // above (tenant-level, no reseller.context needed — BandwidthProfile
    // has no reseller_id at all).
    Route::get('/bandwidth-profiles', BandwidthProfileIndex::class)->name('bandwidth-profiles.index');

    // v0.14.2 — same cluster "Profil Paket", same posture as
    // /bandwidth-profiles above (tenant-level, no reseller.context needed).
    Route::get('/customer-ip-pools', CustomerIpPoolIndex::class)->name('customer-ip-pools.index');

    // v0.14.3 — same cluster "Profil Paket", same posture as
    // /customer-ip-pools above. URL/route name kept English kebab-case
    // matching the class name, consistent with every other entity in this
    // cluster (bandwidth-profiles, customer-ip-pools) — "Grup Profil" is
    // this feature's Indonesian display label (sidebar/page title), not
    // its URL.
    Route::get('/network-profile-groups', NetworkProfileGroupIndex::class)->name('network-profile-groups.index');

    // v0.14.4 — same cluster "Profil Paket", same posture as
    // /network-profile-groups above.
    Route::get('/hotspot-packages', HotspotPackageIndex::class)->name('hotspot-packages.index');

    // v0.14.5 — same cluster "Profil Paket", same posture as
    // /hotspot-packages above.
    Route::get('/ppp-packages', PppPackageIndex::class)->name('ppp-packages.index');

    // v0.16.0 Core Network Infrastructure Management, Langkah 3 —
    // FiberNodeForm is a genuinely separate Livewire component from
    // FiberNodeIndex (unlike every other module in this cluster, which
    // bakes create/edit into one mega-component) so it can be reused
    // as-is for editing an existing Odp too (see /odps/{odp}/edit below)
    // — needs its own routes for both create and edit entry points.
    Route::get('/fiber-nodes', FiberNodeIndex::class)->name('fiber-nodes.index');
    Route::get('/fiber-nodes/create', FiberNodeForm::class)->name('fiber-nodes.create');
    Route::get('/fiber-nodes/{fiber_node}/edit', FiberNodeForm::class)->name('fiber-nodes.edit');
    // v0.16.0 Langkah 4 — splice-diagram detail view, one component shared
    // by both FiberNode and Odp targets (see FiberNodeDetail's own
    // docblock) via two separate routes.
    Route::get('/fiber-nodes/{fiber_node}/detail', FiberNodeDetail::class)->name('fiber-nodes.detail');
    Route::get('/odps/{odp}/detail', FiberNodeDetail::class)->name('odps.detail');
    Route::get('/capacity-report', CapacityReport::class)->name('capacity-report.index');
    // v0.16.0 Langkah 8 — "Peta Topologi": Leaflet map of all topology
    // points, cable lines drawn on-demand per selected core (?core=<id>
    // deep-link from FiberNodeDetail's "Koneksi Core" table), with
    // editable per-cable waypoints.
    Route::get('/fiber-topology-map', FiberTopologyMap::class)->name('fiber-topology-map.index');
    // v0.16.0 Langkah 11 — "Cek Jalur ke ODP": prospect point → nearest
    // ODP candidates → real road route(s) via self-hosted OSRM
    // (RoutingService). Reference-only, saved to sales_route_notes.
    Route::get('/cek-jalur', OdpRouteCheck::class)->name('odp-route-check.index');
    // v0.16.0 Langkah 5 — add an outgoing cable from a topology point,
    // one component shared by both FiberNode and Odp sources (same
    // two-routes-one-component pattern as the detail view above).
    Route::get('/fiber-nodes/{fiber_node}/cables/create', FiberCableForm::class)->name('fiber-nodes.cables.create');
    Route::get('/odps/{odp}/cables/create', FiberCableForm::class)->name('odps.cables.create');

    // v0.16.0 Langkah 3 — no Odp web edit page existed anywhere in this
    // codebase before this (confirmed by grep before building — Odp has
    // only ever been API-only, v0.5.0's OdpController, presumably
    // consumed by a field/technician app). This is a genuinely NEW,
    // minimal page — it does NOT touch StoreOdpRequest/UpdateOdpRequest
    // or OdpController at all (those stay exactly as the v0.5.0
    // registration flow left them); it only lets an admin manage the
    // NEW v0.16.0 fields (loss_in_db/loss_out_db, parent link, photos)
    // via the same reusable GpsPhotoCapture component FiberNodeForm uses.
    Route::get('/odps/{odp}/edit', OdpEdit::class)->name('odps.edit');
    Route::get('/fiber-node-photos/{fiber_node_photo}', [FiberNodePhotoController::class, 'show'])->name('fiber-node-photos.show');

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
