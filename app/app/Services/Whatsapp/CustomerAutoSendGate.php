<?php

namespace App\Services\Whatsapp;

use App\Enums\CustomerAutoSendResult;
use App\Enums\WhatsappEventType;
use App\Enums\WhatsappSessionStatus;
use App\Models\Customer;
use App\Models\CustomerAutoSendBreaker;
use App\Models\CustomerAutoSendDecisionLog;
use App\Models\CustomerAutoSendDeferral;
use App\Models\Invoice;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappSession;
use App\Models\WorkOrderDispatchSettings;
use App\Support\Whatsapp\CustomerAutoSendDecision;
use Closure;

/**
 * v0.28.2 (T2) — pengatur kirim bersama untuk SEMUA jalur otomatis pelanggan.
 * Dipanggil HANYA dari WhatsappGatewayService::buildAndQueue jalur
 * `context === null` (titik otoritatif tunggal tetap satu). Mengembalikan
 * HASIL BERALASAN (CustomerAutoSendResult), menggantikan `null` telanjang.
 *
 * Urutan cek (match pertama menang) — disetujui Agung 2026-11-21:
 *  1. SkippedAutoNotifyOff (saklar tenant OFF) — TIDAK dicatat.
 *  2. SkippedOptOut (v0.28.1).
 *  3. BreakerOpen (N kegagalan beruntun).
 *  4. SessionDown (sesi WA ≠ Connected).
 *  5. OutsideWindow (di luar jendela jam).
 *  6. CapReached (plafon harian GABUNGAN per tenant).
 *  7. SkippedTemplateInactive (template nonaktif) — dicek SEBELUM Mode Uji
 *     (pra-rilis item 2) supaya dry-run TIDAK mencatat Simulated untuk event
 *     yang templatenya nonaktif.
 *  8. Mode Uji: invoice_issued → SkippedTestMode (berhenti); due/suspend/
 *     registered → Simulated (tidak kirim, dicatat).
 *  9. Queue (template resolve + log + dispatch via closure); template nonaktif
 *     saat race → SkippedTemplateInactive (jaring pengaman).
 *
 * customer_registered: hasil transien (breaker/sesi/jendela/cap) → DEFERRED
 * (tabel customer_auto_send_deferrals, dievaluasi ulang tiap menit oleh
 * whatsapp:process-deferred-registered). Jalur terjadwal (sweep/reminder):
 * hasil transien = hentikan run.
 */
class CustomerAutoSendGate
{
    public const TIMEZONE = 'Asia/Jakarta';

    public const DEFAULT_WINDOW_START = '08:00';

    public const DEFAULT_WINDOW_END = '20:00';

    public const DEFAULT_CAP = 80;

    public const DEFAULT_BREAKER_THRESHOLD = 10;

    /**
     * v0.28.4 (SEBAR-BATCH) — planner jeda-batch (slot ->delay) + resolver
     * template (dipakai recheckBeforeSend dari job). WhatsappTemplateService
     * sekelas-namespace, tak ada siklus (ia tak bergantung gerbang/gateway).
     */
    public function __construct(
        private readonly CustomerAutoSendBatchPlanner $batchPlanner,
        private readonly WhatsappTemplateService $templateService,
    ) {}

    /** @return list<string> event jalur otomatis pelanggan (ikut cap gabungan). */
    public static function autoEvents(): array
    {
        return [
            WhatsappEventType::InvoiceIssued->value,
            WhatsappEventType::InvoiceDueReminder->value,
            WhatsappEventType::CustomerSuspendedReminder->value,
            WhatsappEventType::CustomerRegistered->value,
        ];
    }

    /**
     * @param  Closure(int): ?WhatsappMessageLog  $queueAction  menjalankan pembuatan
     *                                                          log + dispatch (tail buildAndQueue) dengan delay batch (detik);
     *                                                          null = template nonaktif.
     * @param  Closure(): bool  $templateActive  true bila template event ini
     *                                           ter-resolve aktif (dicek sebelum Mode Uji, pra-rilis item 2).
     */
    public function process(
        WhatsappEventType $event,
        Customer $customer,
        ?Invoice $invoice,
        Closure $queueAction,
        Closure $templateActive,
        bool $fromDeferral = false,
    ): CustomerAutoSendDecision {
        $tenantId = (int) $customer->tenant_id;
        $resellerId = $customer->reseller_id;
        $path = $event->value;
        $settings = WorkOrderDispatchSettings::query()->where('tenant_id', $tenantId)->first();

        // 1. Saklar tenant OFF — tidak dicatat (hindari noise; state tenant 1 kini).
        if ($settings !== null && ! $settings->customer_auto_notify_enabled) {
            return new CustomerAutoSendDecision(CustomerAutoSendResult::SkippedAutoNotifyOff);
        }

        // 2. Opt-out (v0.28.1).
        if ($customer->auto_notify_opt_out) {
            return $this->recordAndDecide($tenantId, $path, $customer, $invoice, CustomerAutoSendResult::SkippedOptOut);
        }

        // 3. Pemutus terbuka (N kegagalan beruntun).
        if ($this->breakerOpen($tenantId, $resellerId)) {
            return $this->transient($event, $tenantId, $customer, $invoice, CustomerAutoSendResult::BreakerOpen, $fromDeferral);
        }

        // 4. Sesi WA tidak Connected.
        if (! $this->sessionConnected($tenantId, $resellerId)) {
            return $this->transient($event, $tenantId, $customer, $invoice, CustomerAutoSendResult::SessionDown, $fromDeferral);
        }

        // 5. Di luar jendela jam.
        if (! $this->withinWindow($settings)) {
            return $this->transient($event, $tenantId, $customer, $invoice, CustomerAutoSendResult::OutsideWindow, $fromDeferral);
        }

        // 6. Plafon harian gabungan.
        if ($this->queuedToday($tenantId) >= $this->cap($settings)) {
            return $this->transient($event, $tenantId, $customer, $invoice, CustomerAutoSendResult::CapReached, $fromDeferral);
        }

        // 7. Template NONAKTIF — dicek SEBELUM Mode Uji (pra-rilis item 2): kalau
        // template event ini tidak aktif, dry-run tidak boleh mencatat Simulated.
        // Catat SkippedTemplateInactive (deduped per-kandidat). Langkah #9
        // (queueAction null) tetap jaring pengaman untuk race deaktivasi.
        if (! $templateActive()) {
            return $this->recordAndDecide($tenantId, $path, $customer, $invoice, CustomerAutoSendResult::SkippedTemplateInactive);
        }

        // 8. Mode Uji.
        if ($settings !== null && $settings->invoice_pdf_test_mode_enabled) {
            if ($event === WhatsappEventType::InvoiceIssued) {
                return $this->recordOncePerDayAndDecide($tenantId, $path, CustomerAutoSendResult::SkippedTestMode);
            }

            // due/suspend/registered = SIMULASI (K9): tidak kirim, dicatat (dedup harian).
            if (! $this->simulatedTodayExists($tenantId, $path, $customer, $invoice)) {
                $this->record($tenantId, $path, $customer, $invoice, CustomerAutoSendResult::Simulated, simulated: true);
            }
            if ($event === WhatsappEventType::CustomerRegistered) {
                $this->completeDeferral($customer, CustomerAutoSendDeferral::STATUS_SIMULATED);
            }

            return new CustomerAutoSendDecision(CustomerAutoSendResult::Simulated);
        }

        // 8.5 (v0.28.4 SEBAR-BATCH) — cadangkan slot jeda-batch. HANYA di jalur
        // antre-sungguhan (setelah semua skip + Mode Uji) → slot dikonsumsi
        // hanya untuk kirim NYATA. Slot melewati akhir jendela (D3) → perlakukan
        // seperti OutsideWindow (slot monoton naik → kandidat berikut pasti juga
        // lewat; kursor TIDAK berubah). customer_registered → DEFERRED via
        // transient(); jalur lain → recordOncePerDay + orkestrator tick-stop.
        $slot = $this->batchPlanner->reserveSlot($tenantId, $resellerId, $settings ?? WorkOrderDispatchSettings::forTenant($tenantId));
        if ($slot === null) {
            return $this->transient($event, $tenantId, $customer, $invoice, CustomerAutoSendResult::OutsideWindow, $fromDeferral);
        }
        $delaySeconds = max(0, $slot->getTimestamp() - now()->getTimestamp());

        // 9. Antre sungguhan dengan delay batch (template di-resolve ulang di
        // closure; null saat race deaktivasi tetap → SkippedTemplateInactive).
        $log = $queueAction($delaySeconds);
        if ($log === null) {
            return $this->recordAndDecide($tenantId, $path, $customer, $invoice, CustomerAutoSendResult::SkippedTemplateInactive);
        }

        if ($event === WhatsappEventType::CustomerRegistered) {
            $this->completeDeferral($customer, CustomerAutoSendDeferral::STATUS_SENT);
        }

        // Queued tidak dicatat ke decisions (sudah ada di whatsapp_message_logs).
        return new CustomerAutoSendDecision(CustomerAutoSendResult::Queued, $log, $delaySeconds);
    }

    /**
     * Hasil transien: customer_registered → DEFERRED (tabel deferral, idempoten);
     * jalur lain → hasil run-stop apa adanya (dicatat ≤1/hari).
     */
    private function transient(
        WhatsappEventType $event,
        int $tenantId,
        Customer $customer,
        ?Invoice $invoice,
        CustomerAutoSendResult $reason,
        bool $fromDeferral,
    ): CustomerAutoSendDecision {
        if ($event === WhatsappEventType::CustomerRegistered) {
            $this->ensureDeferral($customer);
            // Hanya catat Deferred sekali (saat registrasi, bukan tiap tick
            // command deferral yang masih transien).
            if (! $fromDeferral) {
                $this->record($tenantId, $event->value, $customer, $invoice, CustomerAutoSendResult::Deferred, meta: ['reason' => $reason->value]);
            }

            return new CustomerAutoSendDecision(CustomerAutoSendResult::Deferred);
        }

        return $this->recordOncePerDayAndDecide($tenantId, $event->value, $reason);
    }

    /**
     * v0.28.4 (SEBAR-BATCH, A5/D4) — dipanggil SendWhatsappMessageJob TEPAT
     * sebelum kirim, HANYA untuk log otomatis pelanggan (autoEvents +
     * sent_by_user_id null + !is_test). Karena job otomatis kini ber-delay
     * batch, syaratnya bisa berubah antara di-queue dan dieksekusi; cek ulang
     * di sini. Return null = masih boleh kirim; selain itu alasan gagal +
     * SATU baris keputusan (dedup per-kandidat per hari — SkippedAutoNotifyOff
     * tetap tak dicatat, konsisten T2). Pemanggil menandai log Cancelled dan
     * TIDAK memanggil recordOutcome (batal ≠ gagal kirim → tak menaikkan
     * pemutus). Plafon SENGAJA tidak dicek ulang (sudah terhitung saat queue;
     * mengeceknya lagi bisa membatalkan pesan sah hanya karena antrean penuh —
     * di luar maksud D4).
     */
    public function recheckBeforeSend(WhatsappMessageLog $log): ?CustomerAutoSendResult
    {
        $tenantId = (int) $log->tenant_id;
        $resellerId = $log->reseller_id;
        $event = $log->event_type;
        $path = $event->value;

        $settings = WorkOrderDispatchSettings::query()->where('tenant_id', $tenantId)->first();
        $customer = Customer::withoutGlobalScopes()->find($log->customer_id);
        $invoice = $log->invoice_id !== null ? Invoice::withoutGlobalScopes()->find($log->invoice_id) : null;

        // Pelanggan terhapus setelah di-queue — batalkan, tanpa baris keputusan
        // per-kandidat (tak ada customer/invoice bermakna untuk dedup).
        if ($customer === null) {
            return CustomerAutoSendResult::SkippedTemplateInactive;
        }

        if ($settings !== null && ! $settings->customer_auto_notify_enabled) {
            return $this->recordRecheck($tenantId, $path, $customer, $invoice, CustomerAutoSendResult::SkippedAutoNotifyOff);
        }
        if ($settings !== null && $settings->invoice_pdf_test_mode_enabled) {
            return $this->recordRecheck($tenantId, $path, $customer, $invoice, CustomerAutoSendResult::SkippedTestMode);
        }
        if ($customer->auto_notify_opt_out) {
            return $this->recordRecheck($tenantId, $path, $customer, $invoice, CustomerAutoSendResult::SkippedOptOut);
        }
        if ($this->templateService->resolve($event, $tenantId, $resellerId) === null) {
            return $this->recordRecheck($tenantId, $path, $customer, $invoice, CustomerAutoSendResult::SkippedTemplateInactive);
        }
        if ($this->breakerOpen($tenantId, $resellerId)) {
            return $this->recordRecheck($tenantId, $path, $customer, $invoice, CustomerAutoSendResult::BreakerOpen);
        }
        if (! $this->sessionConnected($tenantId, $resellerId)) {
            return $this->recordRecheck($tenantId, $path, $customer, $invoice, CustomerAutoSendResult::SessionDown);
        }
        if (! $this->withinWindow($settings)) {
            return $this->recordRecheck($tenantId, $path, $customer, $invoice, CustomerAutoSendResult::OutsideWindow);
        }

        return null;
    }

    private function recordRecheck(int $tenantId, string $path, Customer $customer, ?Invoice $invoice, CustomerAutoSendResult $result): CustomerAutoSendResult
    {
        if (! $this->recordedCandidateTodayExists($tenantId, $path, $result, $customer, $invoice)) {
            $this->record($tenantId, $path, $customer, $invoice, $result);
        }

        return $result;
    }

    // ── Pemutus (breaker) ────────────────────────────────────────────────

    public function breakerOpen(int $tenantId, ?int $resellerId): bool
    {
        $breaker = CustomerAutoSendBreaker::query()
            ->where('tenant_id', $tenantId)
            ->where('session_key', WhatsappSession::sessionKeyFor($resellerId))
            ->first();

        return $breaker !== null && $breaker->is_open;
    }

    /**
     * Dipanggil SendWhatsappMessageJob untuk jalur OTOMATIS pelanggan
     * (sent_by_user_id null, bukan uji). Sukses reset counter; gagal final
     * increment, buka pemutus saat mencapai threshold.
     */
    public function recordOutcome(WhatsappMessageLog $log, bool $success): void
    {
        if (! in_array($log->event_type->value, self::autoEvents(), true)) {
            return;
        }
        if ($log->sent_by_user_id !== null || $log->is_test) {
            return;
        }

        $tenantId = (int) $log->tenant_id;
        $breaker = $this->breakerFor($tenantId, $log->reseller_id);

        if ($success) {
            if ($breaker->consecutive_failures !== 0) {
                $breaker->update(['consecutive_failures' => 0]);
            }

            return;
        }

        $failures = $breaker->consecutive_failures + 1;
        $threshold = $this->breakerThreshold($tenantId);
        $updates = ['consecutive_failures' => $failures];

        if ($failures >= $threshold && ! $breaker->is_open) {
            $updates['is_open'] = true;
            $updates['open_reason'] = "{$failures} kegagalan kirim otomatis beruntun";
            $updates['opened_at'] = now();
        }

        $breaker->update($updates);
    }

    /**
     * Auto-lanjut: dipanggil WhatsappSessionService::applyStatus saat sesi
     * TRANSISI ke Connected. Tutup pemutus + reset counter.
     */
    public function onSessionConnected(int $tenantId, ?int $resellerId): void
    {
        $breaker = CustomerAutoSendBreaker::query()
            ->where('tenant_id', $tenantId)
            ->where('session_key', WhatsappSession::sessionKeyFor($resellerId))
            ->first();

        if ($breaker !== null && ($breaker->is_open || $breaker->consecutive_failures !== 0)) {
            $breaker->update([
                'is_open' => false,
                'consecutive_failures' => 0,
                'open_reason' => null,
                'resumed_at' => now(),
                'resumed_by' => null,
            ]);
        }
    }

    /** Lanjut MANUAL (perintah artisan whatsapp:auto-send-resume). */
    public function resume(int $tenantId, ?int $resellerId, ?int $userId): CustomerAutoSendBreaker
    {
        $breaker = $this->breakerFor($tenantId, $resellerId);
        $breaker->update([
            'is_open' => false,
            'consecutive_failures' => 0,
            'open_reason' => null,
            'resumed_at' => now(),
            'resumed_by' => $userId,
        ]);

        return $breaker;
    }

    private function breakerFor(int $tenantId, ?int $resellerId): CustomerAutoSendBreaker
    {
        return CustomerAutoSendBreaker::query()->firstOrCreate([
            'tenant_id' => $tenantId,
            'session_key' => WhatsappSession::sessionKeyFor($resellerId),
        ]);
    }

    private function breakerThreshold(int $tenantId): int
    {
        $settings = WorkOrderDispatchSettings::query()->where('tenant_id', $tenantId)->first();

        return max(1, (int) ($settings->customer_auto_send_breaker_threshold ?? self::DEFAULT_BREAKER_THRESHOLD));
    }

    // ── Cek kondisi ──────────────────────────────────────────────────────

    public function sessionConnected(int $tenantId, ?int $resellerId): bool
    {
        $session = WhatsappSession::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($resellerId === null, fn ($q) => $q->whereNull('reseller_id'))
            ->when($resellerId !== null, fn ($q) => $q->where('reseller_id', $resellerId))
            ->first();

        return $session !== null && $session->status === WhatsappSessionStatus::Connected;
    }

    public function withinWindow(?WorkOrderDispatchSettings $settings): bool
    {
        $start = $settings->customer_auto_send_window_start ?? self::DEFAULT_WINDOW_START;
        $end = $settings->customer_auto_send_window_end ?? self::DEFAULT_WINDOW_END;
        $nowHm = now()->setTimezone(self::TIMEZONE)->format('H:i');

        // Perbandingan string 'HH:MM' (zero-padded 24 jam) benar secara leksikografis; end eksklusif.
        return $nowHm >= $start && $nowHm < $end;
    }

    public function cap(?WorkOrderDispatchSettings $settings): int
    {
        return max(1, (int) ($settings->customer_auto_send_daily_cap ?? self::DEFAULT_CAP));
    }

    public function queuedToday(int $tenantId): int
    {
        return WhatsappMessageLog::withoutGlobalScopes()
            ->effective() // v0.28.4 — kecualikan Cancelled (D5); Failed tetap dihitung.
            ->where('tenant_id', $tenantId)
            ->whereIn('event_type', self::autoEvents())
            ->whereNull('sent_by_user_id')
            ->where('is_test', false)
            ->whereDate('created_at', $this->wibToday())
            ->count();
    }

    public function simulatedTodayExists(int $tenantId, string $path, Customer $customer, ?Invoice $invoice): bool
    {
        return $this->recordedCandidateTodayExists($tenantId, $path, CustomerAutoSendResult::Simulated, $customer, $invoice);
    }

    /**
     * v0.28.3 pra-rilis (item 1) — dedup PER-KANDIDAT per hari (WIB): sudah ada
     * baris keputusan (tenant, path, result, invoice/customer, hari ini)?
     * Orkestrator jalan everyMinute dan bisa mengumpulkan ulang kandidat yang
     * SAMA tiap tick bila tidak ada log/Simulated yang menandai "sudah
     * ditangani" (mis. template nonaktif) — tanpa dedup ini SkippedTemplateInactive/
     * SkippedOptOut/Expired membanjiri tabel keputusan. Kunci dedup: invoice_id
     * bila ada, selain itu customer_id (jalur suspend/registrasi tanpa invoice).
     */
    private function recordedCandidateTodayExists(int $tenantId, string $path, CustomerAutoSendResult $result, ?Customer $customer, ?Invoice $invoice): bool
    {
        return CustomerAutoSendDecisionLog::query()
            ->where('tenant_id', $tenantId)
            ->where('path', $path)
            ->where('result', $result->value)
            ->whereDate('created_at', $this->wibToday())
            ->when($invoice !== null, fn ($q) => $q->where('invoice_id', $invoice->id))
            ->when($invoice === null && $customer !== null, fn ($q) => $q->where('customer_id', $customer->id))
            ->exists();
    }

    // ── Deferral customer_registered ─────────────────────────────────────

    private function ensureDeferral(Customer $customer): void
    {
        CustomerAutoSendDeferral::query()->firstOrCreate(
            ['customer_id' => $customer->id, 'status' => CustomerAutoSendDeferral::STATUS_PENDING],
            ['tenant_id' => $customer->tenant_id],
        );
    }

    private function completeDeferral(Customer $customer, string $status): void
    {
        CustomerAutoSendDeferral::query()
            ->where('customer_id', $customer->id)
            ->where('status', CustomerAutoSendDeferral::STATUS_PENDING)
            ->update(['status' => $status, 'processed_at' => now()]);
    }

    /**
     * v0.28.3 (T3) — dipanggil CustomerAutoSendOrchestrator saat deferral
     * registrasi 'pending' sudah menunggu > 48 jam tanpa pernah terkirim.
     * Tandai 'expired' (baris TIDAK dihapus — jejak) + catat SATU baris
     * keputusan (CustomerAutoSendResult::Expired). Decision-writing tetap di
     * gerbang supaya orkestrator tak menduplikasi logika pencatatan.
     */
    public function expireDeferral(CustomerAutoSendDeferral $deferral): void
    {
        $deferral->update([
            'status' => CustomerAutoSendDeferral::STATUS_EXPIRED,
            'processed_at' => now(),
        ]);

        // Dedup PER-KANDIDAT per hari (belt-and-suspenders): status deferral kini
        // EXPIRED → tidak terkumpul ulang, tapi guard tetap konsisten dengan
        // SkippedOptOut/SkippedTemplateInactive.
        $tenantId = (int) $deferral->tenant_id;
        if (! $this->recordedCandidateTodayExists($tenantId, WhatsappEventType::CustomerRegistered->value, CustomerAutoSendResult::Expired, $deferral->customer, null)) {
            $this->record(
                $tenantId,
                WhatsappEventType::CustomerRegistered->value,
                $deferral->customer,
                null,
                CustomerAutoSendResult::Expired,
                meta: ['reason' => 'deferral_expired_48h'],
            );
        }
    }

    // ── Pencatatan keputusan ─────────────────────────────────────────────

    private function recordAndDecide(int $tenantId, string $path, Customer $customer, ?Invoice $invoice, CustomerAutoSendResult $result): CustomerAutoSendDecision
    {
        // Dedup PER-KANDIDAT per hari (SkippedOptOut / SkippedTemplateInactive) —
        // lihat recordedCandidateTodayExists(): cegah banjir baris dari tick
        // everyMinute orkestrator.
        if (! $this->recordedCandidateTodayExists($tenantId, $path, $result, $customer, $invoice)) {
            $this->record($tenantId, $path, $customer, $invoice, $result);
        }

        return new CustomerAutoSendDecision($result);
    }

    private function recordOncePerDayAndDecide(int $tenantId, string $path, CustomerAutoSendResult $result): CustomerAutoSendDecision
    {
        $exists = CustomerAutoSendDecisionLog::query()
            ->where('tenant_id', $tenantId)
            ->where('path', $path)
            ->where('result', $result->value)
            ->whereDate('created_at', $this->wibToday())
            ->exists();

        if (! $exists) {
            $this->record($tenantId, $path, null, null, $result);
        }

        return new CustomerAutoSendDecision($result);
    }

    private function record(int $tenantId, string $path, ?Customer $customer, ?Invoice $invoice, CustomerAutoSendResult $result, bool $simulated = false, ?array $meta = null): void
    {
        if (! $result->isRecorded()) {
            return;
        }

        CustomerAutoSendDecisionLog::create([
            'tenant_id' => $tenantId,
            'path' => $path,
            'customer_id' => $customer?->id,
            'invoice_id' => $invoice?->id,
            'result' => $result->value,
            'simulated' => $simulated,
            'meta' => $meta,
        ]);
    }

    private function wibToday(): string
    {
        return now()->setTimezone(self::TIMEZONE)->toDateString();
    }
}
