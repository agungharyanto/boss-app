<?php

use App\Http\Middleware\SetLocale;
use App\Livewire\Customers\CustomerIndex;
use App\Livewire\Customers\CustomerShow;
use App\Livewire\Customers\RegisterCustomer;
use App\Livewire\Settings\ThemeSettings;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Public — guests can switch language too, not just logged-in users.
Route::get('/lang/{locale}', function (string $locale) {
    abort_unless(in_array($locale, SetLocale::SUPPORTED, true), 404);

    session(['locale' => $locale]);

    if (auth()->check()) {
        auth()->user()->preference()->updateOrCreate([], ['locale' => $locale]);
    }

    return redirect()->back();
})->name('lang.switch');

Route::middleware('auth')->name('web.')->group(function () {
    Route::get('/customers', CustomerIndex::class)->name('customers.index');
    Route::get('/customers/register', RegisterCustomer::class)->name('customers.register');
    Route::get('/customers/{customer}', CustomerShow::class)->name('customers.show');

    Route::get('/settings/theme', ThemeSettings::class)->name('settings.theme');
});
