<?php

use App\Console\Commands\AutoMatchLegacyDevices;
use App\Console\Commands\GenerateDueInvoices;
use App\Console\Commands\MarkOverdueInvoices;
use App\Console\Commands\ReconcileCpeDevices;
use App\Console\Commands\SendWhatsappDueReminders;
use App\Console\Commands\SendWhatsappSuspendedReminders;
use App\Console\Commands\SyncCpeConnectedHosts;
use App\Console\Commands\SyncCpeDeviceStatus;
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

// v0.7.1 GenieACS Core — reconciles CpeDevice rows bound before their first
// real TR-069 Inform (see CpeBindingService::reconcilePending()'s own
// docblock). 5 minutes is plenty for "device physically powers on and dials
// home" — this isn't a liveness/failover check needing tight polling.
Schedule::command(ReconcileCpeDevices::class)->everyFiveMinutes();

// v0.7.6 Connected Clients — reads whatever GenieACS already has stored for
// each online device's Hosts.Host object (never forces a refreshObject/
// connection_request itself). Same 5-minute cadence as the reconciliation
// job above — connected-client churn doesn't need tighter polling than that.
Schedule::command(SyncCpeConnectedHosts::class)->everyFiveMinutes();

// Legacy MixRadius import follow-up — matches a GenieACS device to a
// legacy customer via legacy_mac_customer_map the moment it becomes visible
// in GenieACS (now or any time in the future), continuously, unlike the
// one-shot 28-device import batch. Slower cadence than the two jobs above —
// see App\Console\Commands\AutoMatchLegacyDevices's own docblock for why.
Schedule::command(AutoMatchLegacyDevices::class)->everyFifteenMinutes();

// Closes the gap where cpe_devices.status/last_inform_at were only ever
// written once, at bind/reconcile time, and never refreshed again — see
// App\Services\Network\CpeDeviceStatusSyncService's own docblock. Same
// cadence as the legacy matcher above, for the same "not time-sensitive
// enough to need the 5-minute jobs' tighter polling" reasoning.
Schedule::command(SyncCpeDeviceStatus::class)->everyFifteenMinutes();
