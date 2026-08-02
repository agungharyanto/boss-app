<?php

use App\Livewire\Customers\CustomerIndex;
use App\Livewire\Customers\CustomerShow;
use App\Livewire\Customers\RegisterCustomer;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->name('web.')->group(function () {
    Route::get('/customers', CustomerIndex::class)->name('customers.index');
    Route::get('/customers/register', RegisterCustomer::class)->name('customers.register');
    Route::get('/customers/{customer}', CustomerShow::class)->name('customers.show');
});
