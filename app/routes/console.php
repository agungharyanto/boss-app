<?php

use App\Console\Commands\GenerateDueInvoices;
use App\Console\Commands\MarkOverdueInvoices;
use App\Console\Commands\SendWhatsappDueReminders;
use App\Console\Commands\SendWhatsappSuspendedReminders;
use App\Console\Commands\VpnCheckNodeHealth;
use App\Console\Commands\WhatsappCheckSessionHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// v0.3.4 Invoicing Core — picked up by boss-scheduler's schedule:run loop.
Schedule::command(GenerateDueInvoices::class)->daily();
Schedule::command(MarkOverdueInvoices::class)->daily();

// v0.4.0 WhatsApp Gateway — picked up by the same boss-scheduler loop.
// SendWhatsappDueReminders self-gates against the admin-configurable
// daily_schedule_times (whatsapp_gateway_settings), so it's scheduled every
// minute rather than at a fixed cron time.
Schedule::command(SendWhatsappDueReminders::class)->everyMinute();
Schedule::command(SendWhatsappSuspendedReminders::class)->dailyAt('08:00');
Schedule::command(WhatsappCheckSessionHealth::class)->hourly();

// v0.6.4 Multi-Node VPN Pool — same boss-scheduler loop. everyMinute()
// matches boss-scheduler's own 60s polling granularity (no point checking
// more often than the loop that actually runs schedule:run).
Schedule::command(VpnCheckNodeHealth::class)->everyMinute();
