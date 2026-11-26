<?php

namespace App\Services\Whatsapp;

use App\Enums\CustomerAutoSendResult;
use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Enums\WhatsappEventType;
use App\Enums\WhatsappMessageStatus;
use App\Models\Customer;
use App\Models\CustomerAutoSendDeferral;
use App\Models\Invoice;
use App\Models\WhatsappMessageLog;
use App\Models\WorkOrderDispatchSettings;
use App\Services\Billing\InvoicePdfService;
use App\Support\Whatsapp\AutoSendCandidate;
use App\Support\Whatsapp\DueReminderDays;
use App\Support\Whatsapp\WhatsappAttachment;
use App\Support\WhatsappPhone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * v0.28.3 (T3, A3 Opsi 1) — ORKESTRATOR kirim otomatis pelanggan bersama.
 *
 * Satu titik terjadwal (whatsapp:auto-send-dispatch, everyMinute) yang
 * MENGGANTIKAN empat jalur terjadwal lama (due/suspend/sweep/process-deferred).
 * Per tenant: kumpulkan kandidat dari semua jalur, urutkan sesuai PRIORITAS
 * K13, lalu lewatkan satu per satu ke GERBANG (buildAndQueueGated). Gerbang
 * tetap satu-satunya penegak + titik otoritatif; orkestrator TIDAK pernah
 * mengirim sendiri.
 *
 * PRIORITAS K13 (kecil = lebih dulu):
 *   H-0 (prioritas 0) → H-x terdekat (prioritas = lead-day d) → suspend (100)
 *   → invoice terbit/sweep (200) → registrasi dari deferral (300).
 *   Tie-break deterministik dalam band: invoice_id / customer_id / deferral_id.
 *
 * REAKSI HASIL (B2):
 *   - Queued → hitung, lanjut.
 *   - CapReached / OutsideWindow / SessionDown / BreakerOpen → HENTIKAN tick
 *     tenant ini (kondisi tenant-global; sisa kandidat menunggu tick/hari
 *     berikutnya). CATATAN: ini SENGAJA lebih sempit dari
 *     CustomerAutoSendResult::isRunStop() — SkippedTestMode & (lihat bawah)
 *     TIDAK menghentikan tick di orkestrator.
 *   - SkippedTestMode → lanjut (Mode Uji hanya menghentikan invoice_issued
 *     per-kandidat; due/suspend/registrasi tetap jadi Simulated).
 *   - SkippedTemplateInactive → lanjut (nonaktif per-event, bukan tenant-wide;
 *     jangan hentikan event lain yang templatenya aktif).
 *   - SkippedOptOut / Simulated / Deferred → lanjut.
 *
 * Gerbang menandai deferral registrasi 'sent'/'simulated' (Queued/Simulated)
 * atau menjaga 'pending' (Deferred). Orkestrator menangani sisa siklus hidup
 * deferral yang gerbang tidak sentuh: EXPIRED > 48 jam (+ 1 baris keputusan),
 * dan BATALKAN saat skip terminal (opt-out / saklar OFF / template nonaktif).
 */
class CustomerAutoSendOrchestrator
{
    public const PATH_DUE = 'due';

    public const PATH_SUSPEND = 'suspend';

    public const PATH_INVOICE = 'invoice';

    public const PATH_REGISTRATION = 'registration';

    /** @var list<string> */
    public const ALL_PATHS = [self::PATH_DUE, self::PATH_SUSPEND, self::PATH_INVOICE, self::PATH_REGISTRATION];

    public const PRIORITY_SUSPEND = 100;

    public const PRIORITY_INVOICE = 200;

    public const PRIORITY_REGISTRATION = 300;

    public const DEFERRAL_EXPIRY_HOURS = 48;

    /**
     * v0.28.4 (SEBAR-BATCH, A6) — flag jeda orkestrator (Cache). Saat ON, tick
     * LANGSUNG keluar dari pengumpulan kandidat (hanya reaper A3 yang tetap
     * jalan) — dipakai command uji whatsapp:auto-send-batch-test agar
     * orkestrator tak mengumpulkan kandidat NYATA selama Mode Uji dimatikan
     * sementara. TTL pendek = pengaman: flag tak bisa macet selamanya walau
     * command crash sebelum melepasnya (finally tetap melepas lebih dulu).
     */
    public const PAUSE_CACHE_KEY = 'whatsapp:auto_send_dispatch_paused';

    public const PAUSE_TTL_MINUTES = 25;

    /** Pembersih A3: jendela berakhir + N menit → log queued hari ini dibatalkan. */
    public const STUCK_GRACE_MINUTES = 15;

    public function __construct(
        private readonly WhatsappGatewayService $gateway,
        private readonly CustomerAutoSendGate $gate,
        private readonly InvoicePdfService $pdfService,
    ) {}

    /**
     * Jalankan tick untuk SEMUA tenant yang relevan: tenant yang punya baris
     * pengaturan + tenant yang punya deferral registrasi 'pending' (supaya
     * deferral selalu terproses meski tenant belum punya baris pengaturan —
     * forTenant() membuatkan default aman). Mengikuti model enumerasi SWEEP
     * (per baris pengaturan), bukan scan Invoice/Customer lintas tenant.
     *
     * @param  list<string>  $paths
     */
    public function dispatchAllTenants(array $paths = self::ALL_PATHS, bool $dryRun = false): int
    {
        $tenantIds = WorkOrderDispatchSettings::query()->pluck('tenant_id')
            ->merge(
                CustomerAutoSendDeferral::query()
                    ->where('status', CustomerAutoSendDeferral::STATUS_PENDING)
                    ->distinct()
                    ->pluck('tenant_id')
            )
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $paused = $this->isPaused();

        $total = 0;
        foreach ($tenantIds as $tenantId) {
            $settings = WorkOrderDispatchSettings::forTenant($tenantId);

            // Dry-run (preview manual, v0.28.6): JANGAN mutasi apa pun — lewati
            // pembersih A3 (ia meng-update log) dan abaikan flag jeda. Selain
            // dry-run, perilaku persis sama seperti sebelumnya.
            if (! $dryRun) {
                // Pembersih A3 — SELALU jalan (termasuk di luar jendela & saat
                // flag jeda ON): satu-satunya bagian tick yang tetap aktif saat
                // paused.
                $this->cleanupStuckLogs($settings);

                // Flag jeda (uji A6): JANGAN kumpulkan kandidat nyata.
                if ($paused) {
                    continue;
                }
            }

            $total += $this->dispatchTenant($settings, $paths, $dryRun);
        }

        return $total;
    }

    /**
     * v0.28.6 (penutupan cluster) — jalankan SATU jalur untuk semua tenant
     * lewat orkestrator + GERBANG yang sama. Dipakai keempat command pembungkus
     * tipis (whatsapp:send-due-reminders / -suspended-reminders /
     * invoices:send-pdf-automatically / whatsapp:process-deferred-registered)
     * agar tak ada lagi query/dedup duplikat di command. $dryRun = hitung
     * kandidat yang AKAN dicoba ke gerbang, tanpa mutasi/kirim.
     */
    public function runPath(string $path, bool $dryRun = false): int
    {
        if (! in_array($path, self::ALL_PATHS, true)) {
            throw new \InvalidArgumentException("Jalur kirim otomatis tidak dikenal: {$path}");
        }

        return $this->dispatchAllTenants([$path], $dryRun);
    }

    public function isPaused(): bool
    {
        return (bool) Cache::get(self::PAUSE_CACHE_KEY, false);
    }

    /**
     * v0.28.4 (SEBAR-BATCH, A3) — batalkan log KIRIM OTOMATIS pelanggan yang
     * masih `queued` padahal tak mungkin terkirim lagi hari ini: baik dari hari
     * SEBELUMNYA, maupun sisa hari ini setelah jendela berakhir + STUCK_GRACE.
     * Status → Cancelled + alasan; TIDAK menaikkan pemutus (batal ≠ gagal). Log
     * MANUAL (sent_by_user_id) / OTP / grup (event di luar autoEvents) / uji
     * TIDAK tersentuh. Return jumlah baris dibatalkan.
     */
    public function cleanupStuckLogs(WorkOrderDispatchSettings $settings): int
    {
        $tenantId = (int) $settings->tenant_id;

        // "Lewat jendela?" dihitung di WIB (jam jendela selalu WIB). Batas hari
        // ("hari sebelumnya") pakai startOfDay zona APP agar cocok dengan cara
        // queued_at disimpan/dibaca (hindari ketidakcocokan wall-clock lintas-tz).
        $nowWib = CarbonImmutable::now(CustomerAutoSendGate::TIMEZONE);
        $startOfToday = CarbonImmutable::now()->startOfDay();

        $end = $settings->customer_auto_send_window_end ?? CustomerAutoSendGate::DEFAULT_WINDOW_END;
        [$h, $m] = array_map('intval', explode(':', $end));
        $pastTodayWindow = $nowWib->greaterThan($nowWib->setTime($h, $m, 0)->addMinutes(self::STUCK_GRACE_MINUTES));

        return WhatsappMessageLog::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', WhatsappMessageStatus::Queued->value)
            ->whereIn('event_type', CustomerAutoSendGate::autoEvents())
            ->whereNull('sent_by_user_id')
            ->where('is_test', false)
            ->where(function ($q) use ($startOfToday, $pastTodayWindow) {
                // Hari sebelumnya → selalu basi.
                $q->where('queued_at', '<', $startOfToday);
                // Sisa hari ini → basi hanya bila jendela sudah lewat + grace.
                if ($pastTodayWindow) {
                    $q->orWhere('queued_at', '>=', $startOfToday);
                }
            })
            ->update([
                'status' => WhatsappMessageStatus::Cancelled->value,
                'failed_reason' => 'kedaluwarsa: tidak terkirim dalam jendela',
            ]);
    }

    /**
     * Satu tenant: pra-cek jendela SENYAP (tak dicatat — konsisten pola sweep
     * T2), kumpulkan kandidat per jalur, urutkan K13, lewatkan ke gerbang
     * sampai menemui kondisi tick-stop.
     *
     * Kolektor due/suspend/invoice di-pra-filter saklar `customer_auto_notify_enabled`
     * (optimasi identik early-exit command lama + query sweep; BUKAN bypass
     * gerbang — setiap KIRIM tetap lewat gerbang). Jalur REGISTRASI tetap
     * dikumpulkan walau saklar OFF, supaya deferran-nya dibatalkan (bukan
     * menggantung) — gerbang mengembalikan SkippedAutoNotifyOff (tak dicatat),
     * orkestrator membatalkan deferral.
     *
     * @param  list<string>  $paths
     */
    public function dispatchTenant(WorkOrderDispatchSettings $settings, array $paths = self::ALL_PATHS, bool $dryRun = false): int
    {
        // Pra-cek jendela SENYAP: di luar jendela, NOL pekerjaan (kandidat +
        // deferral menunggu tick dalam jendela; kadaluwarsa deferral juga
        // dievaluasi hanya saat tick dalam jendela). Dry-run (preview manual,
        // v0.28.6) BOLEH kapan saja — lewati gate jendela.
        if (! $dryRun && ! $this->gate->withinWindow($settings)) {
            return 0;
        }

        $tenantId = (int) $settings->tenant_id;
        $autoNotify = (bool) $settings->customer_auto_notify_enabled;

        /** @var list<AutoSendCandidate> $candidates */
        $candidates = [];

        if ($autoNotify && in_array(self::PATH_DUE, $paths, true)) {
            $candidates = array_merge($candidates, $this->collectDue($tenantId, $settings));
        }
        if ($autoNotify && in_array(self::PATH_SUSPEND, $paths, true)) {
            $candidates = array_merge($candidates, $this->collectSuspend($tenantId));
        }
        if ($autoNotify && $settings->invoice_pdf_auto_send_enabled && in_array(self::PATH_INVOICE, $paths, true)) {
            $candidates = array_merge($candidates, $this->collectInvoice($tenantId, $settings));
        }
        if (in_array(self::PATH_REGISTRATION, $paths, true)) {
            $candidates = array_merge($candidates, $this->collectRegistration($tenantId));
        }

        // Urutkan K13: [priority, tieBreak] menaik — deterministik penuh.
        usort(
            $candidates,
            fn (AutoSendCandidate $a, AutoSendCandidate $b) => [$a->priority, $a->tieBreak] <=> [$b->priority, $b->tieBreak],
        );

        // Dry-run: hitung kandidat yang AKAN dicoba ke gerbang (registrasi yang
        // akan kedaluwarsa tidak dihitung sebagai "akan dikirim"), tanpa
        // memanggil gerbang / membangun lampiran / menyentuh apa pun.
        if ($dryRun) {
            return count(array_filter(
                $candidates,
                fn (AutoSendCandidate $c) => $c->deferral === null || ! $this->isExpired($c->deferral),
            ));
        }

        return $this->dispatchCandidates($candidates, (bool) $settings->invoice_pdf_test_mode_enabled);
    }

    /**
     * @param  list<AutoSendCandidate>  $candidates
     */
    private function dispatchCandidates(array $candidates, bool $testMode): int
    {
        $queued = 0;

        foreach ($candidates as $candidate) {
            // Registrasi: kadaluwarsa > 48 jam → expired + 1 baris keputusan,
            // JANGAN panggil gerbang.
            if ($candidate->deferral !== null && $this->isExpired($candidate->deferral)) {
                $this->gate->expireDeferral($candidate->deferral);

                continue;
            }

            // Lampiran PDF HANYA dibangun saat BUKAN Mode Uji: invoice_issued di
            // Mode Uji pasti SkippedTestMode (gerbang langkah #7, sebelum closure
            // kirim) → lampiran tak pernah dipakai, jadi jangan render dompdf
            // sia-sia. Hasil pra-#7 (Cap/Window/Session/Breaker) juga tak pakai
            // lampiran. Gerbang tetap menghasilkan baris keputusan SkippedTestMode.
            $attachment = null;
            if ($candidate->needsAttachment && $candidate->invoice !== null && ! $testMode) {
                try {
                    $this->pdfService->store($candidate->invoice);
                    $attachment = WhatsappAttachment::fromInvoicePdf($candidate->invoice, (int) $candidate->invoice->tenant_id);
                } catch (\Throwable $e) {
                    Log::warning('CustomerAutoSendOrchestrator: gagal render/lampirkan PDF invoice', [
                        'invoice_id' => $candidate->invoice->id,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }
            }

            try {
                $decision = $this->gateway->buildAndQueueGated(
                    $candidate->event,
                    $candidate->customer,
                    $candidate->invoice,
                    $attachment,
                    fromDeferral: $candidate->deferral !== null,
                );
            } catch (\Throwable $e) {
                Log::warning('CustomerAutoSendOrchestrator: gagal mengantre satu kandidat', [
                    'event' => $candidate->event->value,
                    'customer_id' => $candidate->customer->id,
                    'invoice_id' => $candidate->invoice?->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $result = $decision->result;

            if ($result === CustomerAutoSendResult::Queued) {
                $queued++;

                continue;
            }

            // Kondisi tenant-global → hentikan tick (SENGAJA lebih sempit dari
            // isRunStop(): SkippedTestMode & SkippedTemplateInactive di sini
            // LANJUT, bukan stop).
            if ($this->isTickStop($result)) {
                break;
            }

            // Skip terminal pada deferral registrasi → batalkan (gerbang tidak
            // menyentuh deferral untuk hasil ini). Queued/Simulated sudah
            // ditandai gerbang; Deferred tetap 'pending'.
            if ($candidate->deferral !== null && in_array($result, [
                CustomerAutoSendResult::SkippedOptOut,
                CustomerAutoSendResult::SkippedAutoNotifyOff,
                CustomerAutoSendResult::SkippedTemplateInactive,
            ], true)) {
                $this->cancelDeferral($candidate->deferral);
            }

            // Simulated / Deferred / SkippedTestMode / Skipped* → lanjut kandidat berikutnya.
        }

        return $queued;
    }

    private function isTickStop(CustomerAutoSendResult $result): bool
    {
        return in_array($result, [
            CustomerAutoSendResult::CapReached,
            CustomerAutoSendResult::OutsideWindow,
            CustomerAutoSendResult::SessionDown,
            CustomerAutoSendResult::BreakerOpen,
        ], true);
    }

    // ── Kolektor per jalur ───────────────────────────────────────────────

    /**
     * Pengingat jatuh tempo untuk tiap hari di due_reminder_days (A5). Pre-skip
     * opt-out (A6/K-optout) + dedup harian (terkirim/ter-simulasi hari ini).
     *
     * @return list<AutoSendCandidate>
     */
    private function collectDue(int $tenantId, WorkOrderDispatchSettings $settings): array
    {
        $days = DueReminderDays::sanitize($settings->due_reminder_days);
        $candidates = [];

        foreach ($days as $day) {
            $targetDate = now()->copy()->addDays($day)->toDateString();

            $invoices = Invoice::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('status', InvoiceStatus::Pending->value)
                ->whereDate('due_date', $targetDate)
                ->with('customer')
                ->orderBy('id')
                ->get();

            foreach ($invoices as $invoice) {
                $customer = $invoice->customer;
                if ($customer === null || $customer->auto_notify_opt_out) {
                    continue;
                }
                if ($this->reminderAlreadyHandledToday(WhatsappEventType::InvoiceDueReminder, $customer, $invoice)) {
                    continue;
                }

                $candidates[] = new AutoSendCandidate(
                    event: WhatsappEventType::InvoiceDueReminder,
                    customer: $customer,
                    invoice: $invoice,
                    priority: $day, // H-0 → 0 (paling dulu), lalu H-x menaik.
                    tieBreak: (int) $invoice->id,
                );
            }
        }

        return $candidates;
    }

    /**
     * Pengingat suspend harian. Pre-skip opt-out + dedup harian.
     *
     * @return list<AutoSendCandidate>
     */
    private function collectSuspend(int $tenantId): array
    {
        $customers = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', CustomerStatus::Suspend->value)
            ->where('auto_notify_opt_out', false)
            ->orderBy('id')
            ->get();

        $candidates = [];
        foreach ($customers as $customer) {
            if ($this->reminderAlreadyHandledToday(WhatsappEventType::CustomerSuspendedReminder, $customer, null)) {
                continue;
            }

            $candidates[] = new AutoSendCandidate(
                event: WhatsappEventType::CustomerSuspendedReminder,
                customer: $customer,
                invoice: null,
                priority: self::PRIORITY_SUSPEND,
                tieBreak: (int) $customer->id,
            );
        }

        return $candidates;
    }

    /**
     * Sweep invoice terbit (semantik lama SendInvoicePdfAutomatically):
     * generated_at >= batas bawah (from / enabled_at), Pending, pelanggan
     * Aktif + nomor valid, pre-skip opt-out, dedup = sudah ada log non-uji.
     *
     * @return list<AutoSendCandidate>
     */
    private function collectInvoice(int $tenantId, WorkOrderDispatchSettings $settings): array
    {
        $lowerBound = $this->lowerBoundFor($settings);
        if ($lowerBound === null) {
            return [];
        }

        $invoices = Invoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', InvoiceStatus::Pending->value)
            ->where('generated_at', '>=', $lowerBound)
            ->with('customer')
            ->orderBy('generated_at')
            ->orderBy('id')
            ->get();

        $candidates = [];
        foreach ($invoices as $invoice) {
            $customer = $invoice->customer;
            if ($customer === null || $customer->status !== CustomerStatus::Aktif) {
                continue;
            }
            if ($customer->auto_notify_opt_out) {
                continue;
            }
            $phone = WhatsappPhone::normalize($customer->phone_number);
            if (strlen($phone) < 10 || ! str_starts_with($phone, '62')) {
                continue;
            }
            if ($this->invoiceAlreadySentNonTest($invoice)) {
                continue;
            }

            $candidates[] = new AutoSendCandidate(
                event: WhatsappEventType::InvoiceIssued,
                customer: $customer,
                invoice: $invoice,
                priority: self::PRIORITY_INVOICE,
                tieBreak: (int) $invoice->id,
                needsAttachment: true,
            );
        }

        return $candidates;
    }

    /**
     * Registrasi dari deferral 'pending' (dikonsumsi PALING AKHIR). Deferral
     * tanpa pelanggan (terhapus) langsung dibatalkan, tidak jadi kandidat.
     *
     * @return list<AutoSendCandidate>
     */
    private function collectRegistration(int $tenantId): array
    {
        $deferrals = CustomerAutoSendDeferral::query()
            ->where('tenant_id', $tenantId)
            ->where('status', CustomerAutoSendDeferral::STATUS_PENDING)
            ->with('customer')
            ->orderBy('id')
            ->get();

        $candidates = [];
        foreach ($deferrals as $deferral) {
            $customer = $deferral->customer;
            if ($customer === null) {
                $this->cancelDeferral($deferral);

                continue;
            }

            $candidates[] = new AutoSendCandidate(
                event: WhatsappEventType::CustomerRegistered,
                customer: $customer,
                invoice: null,
                priority: self::PRIORITY_REGISTRATION,
                tieBreak: (int) $deferral->id,
                deferral: $deferral,
            );
        }

        return $candidates;
    }

    // ── Helper ─────────────────────────────────────────────────────────────

    /**
     * Dedup pengingat (due/suspend): sudah terkirim NYATA hari ini (WIB) ATAU
     * sudah ter-SIMULASI hari ini (Mode Uji — Simulated tak membuat
     * WhatsappMessageLog, jadi cek baris keputusan Simulated juga).
     */
    private function reminderAlreadyHandledToday(WhatsappEventType $event, Customer $customer, ?Invoice $invoice): bool
    {
        $sentToday = WhatsappMessageLog::withoutGlobalScopes()
            ->effective() // v0.28.4 — log Cancelled tak dihitung "sudah ditangani" (D5).
            ->where('event_type', $event->value)
            ->when($invoice !== null, fn ($q) => $q->where('invoice_id', $invoice->id))
            ->when($invoice === null, fn ($q) => $q->where('customer_id', $customer->id))
            ->whereDate('created_at', now()->toDateString())
            ->exists();

        if ($sentToday) {
            return true;
        }

        return $this->gate->simulatedTodayExists((int) $customer->tenant_id, $event->value, $customer, $invoice);
    }

    private function invoiceAlreadySentNonTest(Invoice $invoice): bool
    {
        return WhatsappMessageLog::withoutGlobalScopes()
            ->effective() // v0.28.4 — log Cancelled tak dihitung "sudah dikirim" (D5).
            ->where('invoice_id', $invoice->id)
            ->where('event_type', WhatsappEventType::InvoiceIssued->value)
            ->where('is_test', false)
            ->exists();
    }

    private function lowerBoundFor(WorkOrderDispatchSettings $settings): ?CarbonInterface
    {
        if ($settings->invoice_pdf_auto_send_from !== null) {
            return $settings->invoice_pdf_auto_send_from->copy()->startOfDay();
        }

        return $settings->invoice_pdf_auto_send_enabled_at;
    }

    private function isExpired(CustomerAutoSendDeferral $deferral): bool
    {
        return $deferral->created_at !== null
            && $deferral->created_at->lt(now()->subHours(self::DEFERRAL_EXPIRY_HOURS));
    }

    private function cancelDeferral(CustomerAutoSendDeferral $deferral): void
    {
        $deferral->update([
            'status' => CustomerAutoSendDeferral::STATUS_CANCELLED,
            'processed_at' => now(),
        ]);
    }
}
