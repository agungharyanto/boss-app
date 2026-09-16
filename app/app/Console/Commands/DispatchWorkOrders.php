<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\WorkOrderDispatchSettings;
use App\Services\Installation\WorkOrderDispatchService;
use Illuminate\Console\Command;

/**
 * v0.26.2 — Scheduled `->everyMinute()` (lihat routes/console.php), TAPI
 * cadence SEBENARNYA configurable PER TENANT lewat
 * `work_order_dispatch_settings.command_interval_minutes` — pola self-gate
 * di dalam handle() ini persis `SendWhatsappDueReminders` (dikonfirmasi di
 * Langkah 0 sudah ada presedennya untuk "everyMinute + self-gate dari
 * config"), bedanya varian gate-nya: INTERVAL sejak `last_dispatch_run_at`
 * per tenant, bukan cek jam tetap — preseden BARU untuk pola ini, belum
 * ada contoh lain di codebase sebelum command ini.
 *
 * Per-tenant, bukan global — setiap tenant bisa punya
 * `command_interval_minutes` berbeda, jadi throttle-nya dicek per baris
 * `work_order_dispatch_settings`, bukan sekali untuk semua tenant.
 *
 * `last_dispatch_run_at` diupdate SETELAH siklus dispatch+reminder selesai
 * (bukan di awal) — kegagalan di tengah siklus (exception dari satu
 * tenant) tidak diam-diam dianggap "sudah jalan", akan dicoba ulang di
 * cycle berikutnya begitu interval lewat lagi.
 */
class DispatchWorkOrders extends Command
{
    protected $signature = 'work-orders:dispatch';

    protected $description = 'Dispatch Work Order (sesuai janji kunjungan atau segera) + reminder harian, self-throttle per tenant dari work_order_dispatch_settings';

    public function handle(WorkOrderDispatchService $service): int
    {
        foreach (Tenant::all() as $tenant) {
            $settings = WorkOrderDispatchSettings::forTenant($tenant->id);

            if (! $this->intervalElapsed($settings)) {
                continue;
            }

            $dispatched = $service->runDispatchCycle($tenant->id, $settings);
            $reminded = $service->runReminderCycle($tenant->id, $settings);

            $settings->update(['last_dispatch_run_at' => now()]);

            if ($dispatched > 0 || $reminded > 0) {
                $this->info("Tenant #{$tenant->id} ({$tenant->name}): {$dispatched} WO di-dispatch, {$reminded} reminder dikirim.");
            }
        }

        return self::SUCCESS;
    }

    private function intervalElapsed(WorkOrderDispatchSettings $settings): bool
    {
        if ($settings->last_dispatch_run_at === null) {
            return true;
        }

        return $settings->last_dispatch_run_at
            ->copy()
            ->addMinutes($settings->command_interval_minutes)
            ->isPast();
    }
}
