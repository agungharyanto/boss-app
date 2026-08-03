<?php

use App\Livewire\Customers\CustomerIndex;
use App\Livewire\Customers\CustomerShow;
use App\Livewire\Customers\RegisterCustomer;
use App\Livewire\Dashboard;
use App\Livewire\Resellers\PackagePricingIndex;
use App\Livewire\Resellers\ResellerIndex;
use App\Livewire\Resellers\ResellerShow;
use App\Livewire\Settings\ThemeSettings;
use App\Livewire\Tax\ResellerTaxPolicyIndex;
use App\Livewire\Tax\TaxComponentIndex;
use App\Services\LocaleService;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

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
    });

    Route::get('/resellers', ResellerIndex::class)->name('resellers.index');
    Route::get('/resellers/{reseller}', ResellerShow::class)->name('resellers.show');

    Route::get('/tax-components', TaxComponentIndex::class)->name('tax-components.index');

    Route::get('/settings/theme', ThemeSettings::class)->name('settings.theme');
});
