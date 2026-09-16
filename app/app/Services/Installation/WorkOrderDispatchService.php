<?php

namespace App\Services\Installation;

use App\Enums\WhatsappEventType;
use App\Enums\WorkOrderStatus;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Models\WorkOrderDispatchSettings;
use App\Services\Whatsapp\WhatsappGatewayService;
use Illuminate\Database\Eloquent\Collection;
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
 * v0.26.3 — notifikasi WA `WhatsappEventType::WorkOrderDispatched` genuinely
 * dikirim (dulu placeholder `Log::info()` TODO(v0.26.3)). BROADCAST, bukan
 * ke 1 teknisi — keputusan Agung eksplisit (decision-gate v0.26.3, lihat
 * docblock `WhatsappEventType::WorkOrderDispatched`): di titik DISPATCH
 * AWAL, `technician_id` HAMPIR SELALU masih null secara struktural
 * (`assignTechnician()` cuma valid dari status `Ready`, jauh SETELAH
 * dispatch terjadi) — jadi WA japri di titik ini SELALU broadcast ke semua
 * teknisi aktif tenant terkait, "siapa cepat dia dapat". Untuk REMINDER,
 * beda: kalau assign manual SUDAH terjadi (`technician_id` terisi) di
 * titik reminder tercapai, kirim CUMA ke teknisi itu (lebih personal,
 * "tugas Anda belum selesai"); kalau belum, broadcast lagi persis seperti
 * dispatch awal.
 *
 * v0.26.4 — kirim JUGA ke GRUP WA genuinely dikerjakan sekarang (dulu
 * placeholder TODO(v0.26.4)) — KONDISIONAL, hanya kalau tenant sudah
 * setup `work_order_dispatch_settings.wa_group_jid` (Settings "Komunikasi
 * > Konfig WA Gateway", dropdown "Pilih Grup WhatsApp"). Tenant yang belum
 * setup grup TETAP jalan normal (cuma japri individu seperti sebelumnya,
 * bukan prasyarat, tidak pernah error). Event type + isi pesan REUSE
 * PERSIS `WhatsappEventType::WorkOrderDispatched` yang sama dengan japri —
 * lihat `messageVariablesFor()`.
 */
class WorkOrderDispatchService
{
    public function __construct(private readonly WhatsappGatewayService $whatsapp) {}

    /**
     * Dipanggil `WorkOrderService::createFromSubscription()` — WO tanpa
     * janji spesifik dispatch SEKETIKA saat dibuat. Dijalankan DI DALAM
     * transaksi `createFromSubscription()` sendiri (tidak buka transaksi
     * baru di sini). Tidak menerima `$settings` sebagai parameter (beda
     * dari runDispatchCycle()/runReminderCycle() yang dipanggil command
     * dan sudah punya baris settings di tangan) — resolve sendiri di sini.
     */
    public function dispatchImmediately(WorkOrder $workOrder): void
    {
        $workOrder->update(['dispatched_at' => now()]);

        $settings = WorkOrderDispatchSettings::forTenant($workOrder->tenant_id);

        $this->notifyTechnicians($workOrder, isReminder: false);
        $this->notifyGroupIfConfigured($workOrder, $settings->wa_group_jid, isReminder: false);

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
            ->with(['customer', 'subscription'])
            ->where('tenant_id', $tenantId)
            ->whereNull('dispatched_at')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $windowDeadline)
            ->get();

        foreach ($withAppointment as $workOrder) {
            $workOrder->update(['dispatched_at' => $now]);

            $this->notifyTechnicians($workOrder, isReminder: false);
            $this->notifyGroupIfConfigured($workOrder, $settings->wa_group_jid, isReminder: false);

            Log::info("WorkOrderDispatchService: WO #{$workOrder->id} dispatched (window offset {$settings->dispatch_offset_minutes} menit sebelum janji tercapai).");
            $dispatched++;
        }

        // Kasus 2: WO TIDAK punya janji spesifik DAN belum dispatched_at —
        // safety-net untuk WO yang lolos dari dispatchImmediately() (mis.
        // dibuat sebelum sub-versi ini ada). Idempotent by construction:
        // whereNull('dispatched_at') sendiri yang mencegah baris yang
        // sama diproses dua kali di siklus berikutnya.
        $withoutAppointment = WorkOrder::withoutGlobalScopes()
            ->with(['customer', 'subscription'])
            ->where('tenant_id', $tenantId)
            ->whereNull('dispatched_at')
            ->whereNull('scheduled_at')
            ->get();

        foreach ($withoutAppointment as $workOrder) {
            $workOrder->update(['dispatched_at' => $now]);

            $this->notifyTechnicians($workOrder, isReminder: false);
            $this->notifyGroupIfConfigured($workOrder, $settings->wa_group_jid, isReminder: false);

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
            ->with(['customer', 'subscription'])
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

            if ($workOrder->technician_id !== null) {
                $this->notifyAssignedTechnician($workOrder);
            } else {
                $this->notifyTechnicians($workOrder, isReminder: true);
            }

            $this->notifyGroupIfConfigured($workOrder, $settings->wa_group_jid, isReminder: true);

            Log::info("WorkOrderDispatchService: reminder H+ untuk WO #{$workOrder->id} (status masih {$workOrder->status->value}, belum Completed sejak dispatch).");
            $reminded++;
        }

        return $reminded;
    }

    /**
     * Broadcast — dikirim ke SEMUA teknisi aktif tenant terkait, dipakai
     * baik untuk dispatch awal (SELALU broadcast, technician_id belum ada
     * secara struktural di titik itu) maupun reminder saat WO masih
     * genuinely unclaimed (technician_id null). Query REUSE PERSIS filter/
     * urutan `Technician::active()->orderBy('name')` yang sudah dipakai
     * dropdown assign inline di `WorkOrderIndex` (v0.26.2b) — bukan query
     * terpisah yang bisa beda hasil — ditambah `withoutGlobalScopes()`
     * + `where('tenant_id', ...)` eksplisit karena dipanggil dari
     * command/queue context (tidak ada Auth, `TenantScope` tidak otomatis
     * filter di context itu).
     */
    private function notifyTechnicians(WorkOrder $workOrder, bool $isReminder): void
    {
        $technicians = Technician::withoutGlobalScopes()
            ->where('tenant_id', $workOrder->tenant_id)
            ->active()
            ->orderBy('name')
            ->get();

        if ($technicians->isEmpty()) {
            Log::warning("WorkOrderDispatchService: WO #{$workOrder->id} — tidak ada teknisi aktif di tenant #{$workOrder->tenant_id}, notifikasi WA dilewati (WO tetap dispatched/status apa adanya).");

            return;
        }

        $this->sendTo($workOrder, $technicians, $isReminder);
    }

    /**
     * Dipakai reminder cycle saat `technician_id` sudah terisi (assign
     * manual sudah terjadi sebelum window reminder tercapai) — kirim CUMA
     * ke teknisi itu, bukan broadcast. Fallback ke broadcast kalau
     * `technician_id` menunjuk baris yang genuinely sudah tidak ada
     * (jarang — mis. teknisi dihapus setelah di-assign) supaya WO tidak
     * diam-diam kehilangan reminder sama sekali.
     */
    private function notifyAssignedTechnician(WorkOrder $workOrder): void
    {
        $technician = Technician::withoutGlobalScopes()->find($workOrder->technician_id);

        if ($technician === null) {
            Log::warning("WorkOrderDispatchService: WO #{$workOrder->id} — technician_id #{$workOrder->technician_id} tidak ditemukan, broadcast ke semua teknisi aktif sebagai fallback.");
            $this->notifyTechnicians($workOrder, isReminder: true);

            return;
        }

        $this->sendTo($workOrder, Collection::make([$technician]), isReminder: true);
    }

    /**
     * @param  Collection<int, Technician>  $technicians
     */
    private function sendTo(WorkOrder $workOrder, Collection $technicians, bool $isReminder): void
    {
        foreach ($technicians as $technician) {
            $this->whatsapp->buildAndQueueForRecipient(
                WhatsappEventType::WorkOrderDispatched,
                $workOrder->tenant_id,
                $technician->phone,
                $this->messageVariablesFor($workOrder, $technician->name, $isReminder),
                $workOrder->customer,
                $workOrder->reseller_id,
            );
        }
    }

    /**
     * v0.26.4 — kirim JUGA ke grup WA, KONDISIONAL: hanya kalau tenant ini
     * sudah setup `wa_group_jid` (null = belum setup, skip diam-diam, TIDAK
     * error — bukan prasyarat). Isi pesan REUSE PERSIS
     * `messageVariablesFor()` yang sama dengan japri individu, `technician_name`
     * diisi "Tim Teknisi" (grup tidak punya 1 teknisi spesifik yang disapa).
     */
    private function notifyGroupIfConfigured(WorkOrder $workOrder, ?string $groupJid, bool $isReminder): void
    {
        if ($groupJid === null || $groupJid === '') {
            return;
        }

        $this->whatsapp->buildAndQueueForGroup(
            WhatsappEventType::WorkOrderDispatched,
            $workOrder->tenant_id,
            $groupJid,
            $this->messageVariablesFor($workOrder, 'Tim Teknisi', $isReminder),
            $workOrder->reseller_id,
        );
    }

    /**
     * Variabel template `WhatsappEventType::WorkOrderDispatched`, dipakai
     * BERSAMA oleh `sendTo()` (japri individu/broadcast) DAN
     * `notifyGroupIfConfigured()` (grup) — satu sumber kebenaran, supaya
     * isi pesan tidak pernah drift antara dua jalur kirim.
     *
     * @return array<string, string|int|null>
     */
    private function messageVariablesFor(WorkOrder $workOrder, string $technicianName, bool $isReminder): array
    {
        $statusNotice = $isReminder
            ? 'REMINDER — Work Order ini belum selesai, mohon segera ditindaklanjuti.'
            : 'Work Order baru tersedia untuk direspons.';

        return [
            'technician_name' => $technicianName,
            'work_order_id' => $workOrder->id,
            'customer_name' => $workOrder->customer?->name,
            'customer_address' => $workOrder->customer?->address,
            'service_type' => $workOrder->subscription?->name,
            'scheduled_at' => $workOrder->scheduled_at?->translatedFormat('d M Y H:i') ?? 'Tidak ada janji spesifik — segera',
            'status_notice' => $statusNotice,
        ];
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
