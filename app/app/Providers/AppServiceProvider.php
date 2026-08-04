<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Observers\CustomerContactObserver;
use App\Observers\CustomerObserver;
use App\Services\Network\Contracts\RouterOsGateway;
use App\Services\Network\RouterOsApiGateway;
use App\Support\ResellerContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Per-request singleton, defaults to "no reseller" until
        // ResolveResellerContext middleware (or a test) sets one.
        $this->app->singleton(ResellerContext::class, fn () => new ResellerContext);

        // v0.6.1 — tests bind a fake here instead of hitting a real router.
        $this->app->bind(RouterOsGateway::class, RouterOsApiGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Customer::observe(CustomerObserver::class);
        CustomerContact::observe(CustomerContactObserver::class);
    }
}
