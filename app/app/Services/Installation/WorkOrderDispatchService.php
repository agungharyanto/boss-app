<?php

namespace App\Services\Installation;

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use App\Models\WorkOrderDispatchSettings;
use Illuminate\Support\Facades\Log;

/**
 * v0.26.2 — business logic dispatch/reminder Work Order, dipanggil dari 2
 * jalur (dikunci Agung, keputusan HYBRID):
 *  1. `WorkOrderService::createFromSubscription()` — dispatch LANGSUNG
 *     saat create untuk WO tanpa janji spesifik (`scheduled_at` null),
 *     supaya tidak menunggu command jalan.
 *  2. `App\Console\Commands\DispatchWorkOrders` (scheduled `->everyMinute()`,
 *     self-throttle dari `work_order_dispatch_settings.command_interval_minutes`)
 *     — safety-net untuk kasus #1 di atas (WO dibuat sebelum sub-versi ini
 *     ada, atau race), PLUS satu-satunya jalur untuk WO DENGAN janji
 *     spesifik (window `scheduled_at - dispatch_offset_minutes`) dan untuk
 *     reminder harian.
 *
 * TIDAK memanggil `WhatsappGatewayService::buildAndQueueForRecipient()`
 * ATAU membuat `WhatsappEventType` baru sama sekali — dikunci eksplisit di
 * scope v0.26.2 ("cuma nyiapkan STATE-nya, bukan kirim notifikasi").
 * Setiap titik yang NANTINYA (v0.26.3, butuh approval struktur terpisah)
 * akan memanggil notifikasi WA ditandai `// TODO(v0.26.3): ...` — cukup
 * `Log::info()` sebagai placeholder untuk sekarang.
 */
class WorkOrderDispatchService
{
    /**
     * Dipanggil `WorkOrderService::createFromSubscription()` — WO tanpa
     * janji spesifik dispatch SEKETIKA saat dibuat. Dijalankan DI DALAM
     * transaksi `createFromSubscription()` sendiri (tidak buka transaksi
     * baru di sini).
     */
    public function dispatchImmediately(WorkOrder $workOrder): void
    {
        $workOrder->update(['dispatched_at' => now()]);

        // TODO(v0.26.3): WhatsappGatewayService::buildAndQueueForRecipient()
        // dengan WhatsappEventType baru untuk "WO ditugaskan/dispatch" —
        // event type + titik pemanggilan pastinya menunggu approval
        // struktur terpisah, BELUM dibuat di sub-versi ini.
        Log::info("WorkOrderDispatchService: WO #{$workOrder->id} dispatched segera saat create (tanpa janji spesifik).");
    }

    /**
     * Dipanggil `DispatchWorkOrders` command, SATU KALI per tenant per
     * siklus (setelah self-throttle interval lolos). Return jumlah WO
     * yang di-dispatch di siklus ini — dipakai command untuk log ringkas
     * dan test untuk assertion.
     */
    public function runDispatchCycle(int $tenantId, WorkOrderDispatchSettings $settings): int
    {
        $now = now();
        $dispatched = 0;

        // Kasus 1: WO PUNYA janji spesifik, window (scheduled_at - offset)
        // sudah tercapai — scheduled_at <= now + offset berarti "sekarang
        // sudah berada di dalam window offset sebelum janji itu".
        $windowDeadline = $now->copy()->addMinutes($settings->dispatch_offset_minutes);

        $withAppointment = WorkOrder::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('dispatched_at')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $windowDeadline)
            ->get();

        foreach ($withAppointment as $workOrder) {
            $workOrder->update(['dispatched_at' => $now]);

            // TODO(v0.26.3): sama seperti dispatchImmediately() di atas —
            // notifikasi WA (japri teknisi + grup) belum dibangun di sini.
            Log::info("WorkOrderDispatchService: WO #{$workOrder->id} dispatched (window offset {$settings->dispatch_offset_minutes} menit sebelum janji tercapai).");
            $dispatched++;
        }

        // Kasus 2: WO TIDAK punya janji spesifik DAN belum dispatched_at —
        // safety-net untuk WO yang lolos dari dispatchImmediately() (mis.
        // dibuat sebelum sub-versi ini ada). Idempotent by construction:
        // whereNull('dispatched_at') sendiri yang mencegah baris yang
        // sama diproses dua kali di siklus berikutnya.
        $withoutAppointment = WorkOrder::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('dispatched_at')
            ->whereNull('scheduled_at')
            ->get();

        foreach ($withoutAppointment as $workOrder) {
            $workOrder->update(['dispatched_at' => $now]);

            Log::info("WorkOrderDispatchService: WO #{$workOrder->id} dispatched segera oleh command (safety-net, tanpa janji spesifik).");
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Dipanggil `DispatchWorkOrders` command, SATU KALI per tenant per
     * siklus. Reminder cuma efektif kalau jam sekarang masuk window
     * [reminder_time, reminder_time + command_interval_minutes) — window
     * seukuran interval command supaya command yang cuma jalan sekali per
     * interval TETAP PASTI dapat satu kesempatan jatuh di dalam window
     * itu. `last_reminder_sent_at` (DATE) yang menjaga idempoten dalam
     * hari yang sama, BUKAN window jamnya sendiri (window jam cuma
     * menentukan KAPAN command boleh mulai mengirim reminder hari itu,
     * bukan mencegah duplikat).
     */
    public function runReminderCycle(int $tenantId, WorkOrderDispatchSettings $settings): int
    {
        if (! $this->isWithinReminderWindow($settings)) {
            return 0;
        }

        $today = now()->toDateString();
        $reminded = 0;

        $candidates = WorkOrder::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('dispatched_at')
            ->whereNotIn('status', [WorkOrderStatus::Completed->value, WorkOrderStatus::Cancelled->value])
            ->where(function ($query) use ($today) {
                $query->whereNull('last_reminder_sent_at')
                    ->orWhereDate('last_reminder_sent_at', '!=', $today);
            })
            ->get();

        foreach ($candidates as $workOrder) {
            $workOrder->update(['last_reminder_sent_at' => $today]);

            // TODO(v0.26.3): notifikasi WA reminder H+ (japri teknisi) —
            // belum dibangun.
            Log::info("WorkOrderDispatchService: reminder H+ untuk WO #{$workOrder->id} (status masih {$workOrder->status->value}, belum Completed sejak dispatch).");
            $reminded++;
        }

        return $reminded;
    }

    private function isWithinReminderWindow(WorkOrderDispatchSettings $settings): bool
    {
        $now = now();
        [$hour, $minute] = array_map('intval', explode(':', $settings->reminder_time));

        $start = $now->copy()->setTime($hour, $minute, 0);
        $end = $start->copy()->addMinutes($settings->command_interval_minutes);

        return $now->betweenIncluded($start, $end);
    }
}
