<?php

use App\Livewire\Customers\CustomerIndex;
use App\Livewire\Customers\CustomerShow;
use App\Livewire\Customers\RegisterCustomer;
use App\Livewire\Dashboard;
use App\Livewire\Settings\ThemeSettings;
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

    Route::get('/customers', CustomerIndex::class)->name('customers.index');
    Route::get('/customers/register', RegisterCustomer::class)->name('customers.register');
    Route::get('/customers/{customer}', CustomerShow::class)->name('customers.show');

    Route::get('/settings/theme', ThemeSettings::class)->name('settings.theme');
});
