<?php

namespace App\Services\Installation;

use App\Enums\WhatsappEventType;
use App\Enums\WorkOrderDeviceType;
use App\Enums\WorkOrderPhotoType;
use App\Enums\WorkOrderStatus;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Models\WorkOrderDevice;
use App\Models\WorkOrderDispatchSettings;
use App\Models\WorkOrderPhoto;
use App\Services\Whatsapp\WhatsappGatewayService;
use App\Support\Whatsapp\WhatsappAttachment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

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
            ->with(['customer', 'subscription', 'claimedByTechnician'])
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

            // v0.13.4.1 amendment — WO SUDAH diklaim via signed-link
            // (claimed_at terisi): TETAP kirim reminder (bukan skip),
            // TAPI lewat event type BARU (WorkOrderClaimedReminder, tanpa
            // claim_link) dan HANYA ke teknisi yang genuinely klaim —
            // broadcast ke semua teknisi aktif / ke technician_id (field
            // assignment admin yang terpisah) sudah tidak relevan lagi di
            // sini, WO ini bukan "up for grabs".
            if ($workOrder->claimed_at !== null) {
                $this->notifyClaimedReminder($workOrder, $settings->wa_group_jid);

                Log::info("WorkOrderDispatchService: reminder (sudah diklaim) untuk WO #{$workOrder->id} — klaimer teknisi #{$workOrder->claimed_by_technician_id}.");
                $reminded++;

                continue;
            }

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
     * v0.13.4.1 amendment — reminder untuk WO yang SUDAH diklaim via
     * signed-link. Event type TERPISAH (WorkOrderClaimedReminder, lihat
     * docblock enum-nya) — bukan variabel `{status_notice}` di dalam
     * WorkOrderDispatched seperti dispatch-vs-reminder biasa, supaya
     * admin/reseller bisa mengedit teksnya sendiri lewat UI Template WA
     * secara independen. Dikirim CUMA ke teknisi yang genuinely klaim
     * (`claimed_by_technician_id`) — kalau baris itu somehow tidak
     * ditemukan lagi (mis. teknisi dihapus setelah klaim), dicatat
     * sebagai warning dan TIDAK ada fallback broadcast (beda dari
     * notifyAssignedTechnician() — WO yang sudah diklaim bukan "up for
     * grabs" lagi, broadcast ke semua teknisi aktif tidak masuk akal di
     * sini). Grup WA (kalau dikonfigurasi) tetap ikut diberi tahu, sama
     * pola notifyGroupIfConfigured().
     */
    /**
     * v0.13.4.2.2 — dikirim ke teknisi PENGKLAIM segera setelah klaim sukses,
     * membawa signed-link lanjut ke form AKTIVASI pelanggan (unik per teknisi,
     * berlaku 2 hari, pola identik `claim_link` di sendTo()). Kegagalan kirim
     * WA TIDAK pernah menggagalkan klaim — pemanggil (WorkOrderClaimService)
     * membungkus pemanggilan ini dengan try/catch + Log, bukan ditelan diam.
     */
    public function notifyActivationReady(WorkOrder $workOrder, Technician $claimer): void
    {
        $activationLink = URL::temporarySignedRoute(
            'work-orders.activate.show',
            now()->addDays(2),
            ['work_order' => $workOrder->id, 'technician' => $claimer->id],
        );

        // v0.13.4.3.1 — sertakan link tutup WO (tahap-3). Tetap event type yang
        // SAMA (WorkOrderActivationReady), hanya variabel template baru {close_link};
        // TIDAK membuat event type baru.
        $closeLink = URL::temporarySignedRoute(
            'work-orders.close.show',
            now()->addDays(2),
            ['work_order' => $workOrder->id, 'technician' => $claimer->id],
        );

        $this->whatsapp->buildAndQueueForRecipient(
            WhatsappEventType::WorkOrderActivationReady,
            $workOrder->tenant_id,
            $claimer->phone,
            [
                'technician_name' => $claimer->name,
                'customer_name' => $workOrder->customer?->name,
                'work_order_id' => $workOrder->id,
                'activation_link' => $activationLink,
                'close_link' => $closeLink,
            ],
            $workOrder->customer,
            $workOrder->reseller_id,
        );
    }

    /**
     * v0.13.4.3.2 — notifikasi grup WA saat WO Completed: 1 pesan teks
     * ringkasan (event WorkOrderCompleted) lalu 6 foto berurutan via pipeline
     * media v0.13.5.1 (queue whatsapp-{session_key} + satu worker = FIFO, teks
     * lebih dulu). `wa_group_jid` null/'' → skip DIAM-DIAM. BEST-EFFORT —
     * dipanggil dari DB::afterCommit + try/catch di WorkOrderService::complete();
     * kegagalan WA tidak pernah memblokir penutupan WO. Foto yang belum ada
     * (jalur API ungated) dilewati tanpa error.
     */
    public function notifyWorkOrderCompleted(WorkOrder $workOrder): void
    {
        $settings = WorkOrderDispatchSettings::forTenant($workOrder->tenant_id);
        $groupJid = $settings->wa_group_jid;

        if ($groupJid === null || $groupJid === '') {
            return;
        }

        // 1) Teks ringkasan — didispatch DULU (FIFO di queue yang sama).
        $this->whatsapp->buildAndQueueForGroup(
            WhatsappEventType::WorkOrderCompleted,
            $workOrder->tenant_id,
            $groupJid,
            $this->completedVariablesFor($workOrder),
            $workOrder->reseller_id,
        );

        // 2) 6 foto berurutan, caption BERLABEL per-item; lewati tipe yang belum ada.
        $labels = [
            WorkOrderPhotoType::Speedtest->value => '1/6 Speedtest',
            WorkOrderPhotoType::ModemLocation->value => '2/6 Lokasi modem',
            WorkOrderPhotoType::CableRouteIkr->value => '3/6 Jalur kabel IKR',
            WorkOrderPhotoType::OdpPort->value => '4/6 Port ODP',
            WorkOrderPhotoType::OdpBox->value => '5/6 Box ODP',
            WorkOrderPhotoType::CustomerFront->value => '6/6 Tampak depan pelanggan',
        ];

        $attachments = [];
        foreach ($labels as $typeValue => $label) {
            $photo = WorkOrderPhoto::where('work_order_id', $workOrder->id)
                ->where('type', $typeValue)
                ->first();

            if ($photo === null) {
                continue;
            }

            $attachments[] = WhatsappAttachment::fromWorkOrderPhoto(
                $photo,
                $workOrder->tenant_id,
                $label.' · WO #'.$workOrder->id,
            );
        }

        if ($attachments !== []) {
            $this->whatsapp->queueMediaBatchForGroup(
                WhatsappEventType::WorkOrderCompleted,
                $workOrder->tenant_id,
                $groupJid,
                [],
                $attachments,
                $workOrder->reseller_id,
            );
        }
    }

    /**
     * Variabel teks WO-selesai. SSID dari WorkOrderDevice (ONT) — PASSWORD
     * tidak pernah diambil/dikirim (template memakai literal "****").
     *
     * @return array<string, string|int|null>
     */
    private function completedVariablesFor(WorkOrder $workOrder): array
    {
        $ont = WorkOrderDevice::where('work_order_id', $workOrder->id)
            ->where('device_type', WorkOrderDeviceType::Ont)
            ->orderBy('id')
            ->first();

        $technician = $workOrder->claimed_by_technician_id !== null
            ? Technician::withoutGlobalScopes()->find($workOrder->claimed_by_technician_id)
            : null;

        return [
            'technician_name' => $technician?->name ?? 'Tim Teknisi',
            'customer_name' => $workOrder->customer?->name,
            'customer_cid' => $workOrder->customer?->cid,
            'work_order_id' => $workOrder->id,
            'ssid' => $ont?->ssid ?? '-',
            'service_type' => $workOrder->subscription?->name,
        ];
    }

    private function notifyClaimedReminder(WorkOrder $workOrder, ?string $groupJid): void
    {
        $claimer = $workOrder->claimedByTechnician;

        if ($claimer === null) {
            Log::warning("WorkOrderDispatchService: WO #{$workOrder->id} — claimed_by_technician_id #{$workOrder->claimed_by_technician_id} tidak ditemukan, reminder (sudah diklaim) dilewati untuk japri (grup tetap diberi tahu kalau dikonfigurasi).");
        } else {
            $this->whatsapp->buildAndQueueForRecipient(
                WhatsappEventType::WorkOrderClaimedReminder,
                $workOrder->tenant_id,
                $claimer->phone,
                $this->messageVariablesForClaimedReminder($workOrder, $claimer->name),
                $workOrder->customer,
                $workOrder->reseller_id,
            );
        }

        if ($groupJid === null || $groupJid === '') {
            return;
        }

        $this->whatsapp->buildAndQueueForGroup(
            WhatsappEventType::WorkOrderClaimedReminder,
            $workOrder->tenant_id,
            $groupJid,
            $this->messageVariablesForClaimedReminder($workOrder, 'Tim Teknisi'),
            $workOrder->reseller_id,
        );
    }

    /**
     * v0.13.4.1 — signed-link klaim (`web.work-orders.claim.show`, TANPA
     * login, `signature` itu sendiri = otorisasi) di-generate PER TEKNISI
     * di sini (bukan di messageVariablesFor(), yang tidak tahu
     * $technician->id-nya) — link UNIK per penerima, bukan link identik
     * untuk semua broadcast recipient (dikunci eksplisit di kickoff).
     * Kedaluwarsa 2 hari — link BARU digenerate tiap kali pesan WA dikirim
     * (dispatch awal maupun tiap siklus reminder harian), jadi link lama
     * otomatis tergantikan link baru di reminder berikutnya kalau belum
     * sempat diklaim; 2 hari memberi margin wajar untuk WO yang
     * di-dispatch pagi tapi baru dikerjakan besoknya.
     *
     * @param  Collection<int, Technician>  $technicians
     */
    private function sendTo(WorkOrder $workOrder, Collection $technicians, bool $isReminder): void
    {
        foreach ($technicians as $technician) {
            $claimLink = URL::temporarySignedRoute(
                'work-orders.claim.show',
                now()->addDays(2),
                ['work_order' => $workOrder->id, 'technician' => $technician->id],
            );

            $this->whatsapp->buildAndQueueForRecipient(
                WhatsappEventType::WorkOrderDispatched,
                $workOrder->tenant_id,
                $technician->phone,
                $this->messageVariablesFor($workOrder, $technician->name, $isReminder, $claimLink),
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
    private function messageVariablesFor(WorkOrder $workOrder, string $technicianName, bool $isReminder, ?string $claimLink = null): array
    {
        $statusNotice = $isReminder
            ? 'REMINDER — Work Order ini belum selesai, mohon segera ditindaklanjuti.'
            : 'Work Order baru tersedia untuk direspons.';

        // v0.26.0.1 — link Google Maps "directions" (rute langsung), sama
        // format persis yang sudah dipakai OdpEdit/FiberNodeDetail/
        // FiberNodeIndex (konsistensi lintas modul) — bukan format ?q=
        // yang lain. "-" kalau lat/long kosong (dikonfirmasi lewat
        // investigasi: SEMUA customer saat ini belum punya koordinat
        // terisi — bukan kasus langka, ini kondisi normal sampai admin
        // mengisi koordinat manual per-customer).
        $customer = $workOrder->customer;
        $hasCoordinates = $customer?->latitude !== null && $customer?->longitude !== null;
        $coordinates = $hasCoordinates
            ? "https://www.google.com/maps/dir/?api=1&destination={$customer->latitude},{$customer->longitude}"
            : '-';

        return [
            'technician_name' => $technicianName,
            'work_order_id' => $workOrder->id,
            'customer_name' => $customer?->name,
            'customer_cid' => $customer?->cid,
            'customer_address' => $customer?->address,
            'customer_coordinates' => $coordinates,
            'service_type' => $workOrder->subscription?->name,
            'scheduled_at' => $workOrder->scheduled_at?->translatedFormat('d M Y H:i') ?? 'Tidak ada janji spesifik — segera',
            'status_notice' => $statusNotice,
            // v0.13.4.1 — null untuk pesan GRUP (notifyGroupIfConfigured()
            // tidak pernah meneruskan ini — grup tidak identifikasi 1
            // teknisi spesifik, link per-teknisi tidak masuk akal di
            // sana). Template WA produksi saat ini belum menyebut
            // {claim_link} sama sekali (admin bisa tambahkan sendiri lewat
            // UI Template WA kalau mau memakainya) — konsisten pola
            // customer_coordinates/customer_cid (v0.26.0.1), variabel baru
            // selalu tersedia di array, template lama yang belum
            // menyebutnya tetap valid apa adanya.
            'claim_link' => $claimLink,
        ];
    }

    /**
     * v0.13.4.1 amendment — variabel template
     * `WhatsappEventType::WorkOrderClaimedReminder`, dipakai BERSAMA oleh
     * notifyClaimedReminder() untuk japri (ke klaimer) DAN grup. SENGAJA
     * TIDAK ADA key `claim_link` sama sekali di array ini (bukan cuma
     * null) — beda dari messageVariablesFor() di atas, supaya jelas
     * secara desain bahwa variabel itu genuinely tidak relevan di varian
     * ini, bukan cuma kebetulan kosong.
     *
     * @return array<string, string|int|null>
     */
    private function messageVariablesForClaimedReminder(WorkOrder $workOrder, string $technicianName): array
    {
        $customer = $workOrder->customer;

        return [
            'technician_name' => $technicianName,
            'work_order_id' => $workOrder->id,
            'customer_name' => $customer?->name,
            'customer_cid' => $customer?->cid,
            'customer_address' => $customer?->address,
            'service_type' => $workOrder->subscription?->name,
            'claimed_by_name' => $workOrder->claimedByTechnician?->name ?? '-',
            'claimed_at' => $workOrder->claimed_at?->translatedFormat('d M Y H:i') ?? '-',
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
