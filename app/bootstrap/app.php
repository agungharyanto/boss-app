<?php

use App\Http\Middleware\EnsureAdminPanelAccess;
use App\Http\Middleware\EnsureReferrerPortalAccess;
use App\Http\Middleware\ResolveResellerContext;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SetLocale::class]);
        $middleware->alias([
            'reseller.context' => ResolveResellerContext::class,
            'admin.panel' => EnsureAdminPanelAccess::class,
            'referrer.portal' => EnsureReferrerPortalAccess::class,
        ]);

        // Must run before SubstituteBindings — implicit route-model binding
        // (e.g. {pricing} -> ResellerPackagePricing) needs ResellerContext
        // already resolved so App\Models\Scopes\ResellerScope can filter the
        // binding query itself (a wrong-reseller id 404s at binding time,
        // matching TenantScope's isolation shape — see
        // tests/Feature/Tenancy/TenantIsolationTest.php). Without this
        // priority entry, ResolveResellerContext still runs, just too late:
        // binding succeeds unscoped and only the Policy check downstream
        // denies it (403 instead of 404).
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveResellerContext::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
