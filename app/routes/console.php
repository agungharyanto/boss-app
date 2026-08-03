<?php

use App\Console\Commands\GenerateDueInvoices;
use App\Console\Commands\MarkOverdueInvoices;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// v0.3.4 Invoicing Core — picked up by boss-scheduler's schedule:run loop.
Schedule::command(GenerateDueInvoices::class)->daily();
Schedule::command(MarkOverdueInvoices::class)->daily();
