<?php

use App\Console\Commands\GenerateDueInvoices;
use App\Console\Commands\MarkOverdueInvoices;
use App\Console\Commands\ReconcileCpeDevices;
use App\Console\Commands\SendWhatsappDueReminders;
use App\Console\Commands\SendWhatsappSuspendedReminders;
use App\Console\Commands\SyncContainerStats;
use App\Console\Commands\SyncCpeConnectedHosts;
use App\Console\Commands\SyncCpeDeviceStatus;
use App\Console\Commands\SyncCpeSignalHistory;
use App\Console\Commands\VpnCheckNodeHealth;
use App\Console\Commands\VpnSyncRouteFragments;
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

// v0.8.1 fragment+reconcile (replaces the OSPF experiment, see CLAUDE.md) —
// same everyMinute() cadence as VpnCheckNodeHealth above, a different
// question (which node a NAS's tunnel is CURRENTLY on, not whether a node
// container is alive at all).
Schedule::command(VpnSyncRouteFragments::class)->everyMinute();

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
// one-shot 28-device import batch.
//
// Deliberately NOT registered via Schedule::command()->everyX() here —
// its cadence is configurable at runtime (CPE_AUTO_MATCH_INTERVAL_SECONDS,
// root .env, currently 30s while Agung manually adds TR-069 profiles
// per-ONT and wants fast feedback; steady-state default is 60s), which can
// go below Laravel's 1-minute scheduler granularity. Laravel DOES support
// sub-minute frequencies (Schedule::everyThirtySeconds() etc, confirmed
// available in this project's Laravel 12), but ScheduleRunCommand's own
// repeatEvents() loops only until Carbon::now()->endOfMinute() from
// whenever THAT schedule:run invocation started — correct for real cron
// (which fires a fresh invocation aligned to every minute boundary), but
// boss-scheduler's entrypoint (docker-compose.yml) is "while true; do
// schedule:run; sleep 60; done", NOT cron — each invocation can start at
// any offset within a minute, so the sub-minute repeat window would be
// unpredictable (anywhere from ~0 to ~60s) and drift, never a clean
// steady 30s cadence. Confirmed by reading
// vendor/laravel/framework/.../ScheduleRunCommand.php directly, not
// assumed. Handled instead by a second, independent while-loop in
// boss-scheduler's own entrypoint that calls this command directly on its
// own configurable interval — see docker-compose.yml's boss-scheduler
// service comment and AutoMatchLegacyDevicesScheduleTest for the
// regression guard against accidentally re-adding a duplicate/conflicting
// Schedule::command() registration here.

// Closes the gap where cpe_devices.status/last_inform_at were only ever
// written once, at bind/reconcile time, and never refreshed again — see
// App\Services\Network\CpeDeviceStatusSyncService's own docblock.
//
// ->runInBackground() (2026-09-04) — boss-scheduler's entrypoint is a crude
// `while true; do schedule:run; sleep 60; done` loop; a foreground command
// that takes minutes (this one ~2.5min, SyncCpeSignalHistory ~10min) blocks
// the WHOLE loop, so the next `schedule:run` lands minutes late and MISSES
// its `*/15` cron slot (observed: this ran only ~hourly instead of every
// 15 min, and cpe:reconcile only ~every 20 min instead of every 5).
// Forking it frees the loop to hit every slot on time. ->withoutOverlapping()
// guards the (unlikely) case two forks pile up.
Schedule::command(SyncCpeDeviceStatus::class)->everyFifteenMinutes()->runInBackground()->withoutOverlapping();

// v0.8.3 — records RX Power history (App\Models\CpeSignalHistory) for the
// CPE detail page's new history graph. 20 minutes is a locked decision
// (15-30 min range agreed), not derived from any technical constraint here
// — see App\Services\Network\CpeSignalHistoryService's own docblock for the
// full reasoning (deliberately separate from SyncCpeDeviceStatus above,
// different question/cadence/failure model). No named ->everyTwentyMinutes()
// helper exists in Laravel's schedule API, hence the raw cron expression.
// ->withoutOverlapping() is new to this file (no prior scheduled command
// needed it) — this run's own staggered sends + single read-back wait
// genuinely take several minutes end to end (~5.5 min worked example at
// 400 devices, comfortably under 20 min), but a real GenieACS slowdown on
// some future run could push it close to the next scheduled tick, and a
// second run starting on top of an unfinished first is worse than one run
// occasionally starting a few minutes late.
// ->runInBackground() — see SyncCpeDeviceStatus above; this is the ~10min
// command that was starving the scheduler loop the most.
Schedule::command(SyncCpeSignalHistory::class)->cron('*/20 * * * *')->runInBackground()->withoutOverlapping();

// v0.8.4 Bagian C — records container CPU/Memory/Network/Disk history
// (App\Models\ContainerStatsHistory) for the Monitoring page's new
// "Container BOSS App" section. 5 minutes is a measured decision, not a
// guess — App\Services\Infra\ContainerStatsService's own docblock records
// the real timing (~53s for 27 containers on this server, sequential
// per-container /stats calls, each ~2s due to the Docker daemon's own
// internal two-sample wait) that this interval was picked against, with
// wide (5-6x) margin. ->withoutOverlapping() for the same reason as
// SyncCpeSignalHistory above — a slow run overlapping the next tick is
// worse than one run starting a few minutes late.
// ->runInBackground() — see SyncCpeDeviceStatus above; ~53s run, still
// enough to shove the scheduler loop past a `*/5` slot for the fast jobs.
Schedule::command(SyncContainerStats::class)->everyFiveMinutes()->runInBackground()->withoutOverlapping();
