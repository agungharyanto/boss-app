<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Observers\CustomerContactObserver;
use App\Observers\CustomerObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
