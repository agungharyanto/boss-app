<?php

namespace App\Console\Commands;

use App\Enums\CustomerAutoSendResult;
use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Enums\WhatsappEventType;
use App\Models\Invoice;
use App\Models\WhatsappMessageLog;
use App\Models\WorkOrderDispatchSettings;
use App\Services\Billing\InvoicePdfService;
use App\Services\Whatsapp\CustomerAutoSendGate;
use App\Services\Whatsapp\WhatsappGatewayService;
use App\Support\Whatsapp\WhatsappAttachment;
use App\Support\WhatsappPhone;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * v0.13.5.3.1 — SWEEP kirim PDF invoice OTOMATIS ke pelanggan.
 *
 * v0.28.2 (T2) — penegakan dipindah ke CustomerAutoSendGate (lewat
 * `WhatsappGatewayService::buildAndQueueGated`, jalur context null). Sweep ini
 * tinggal: pilih tenant (saklar sweep + saklar pelanggan ON), pra-cek jendela
 * (SENYAP, tak dicatat — aturan tambahan 2), lalu per-invoice minta gerbang.
 * Gerbang mengembalikan HASIL BERALASAN; sweep bereaksi:
 *  - Queued → hitung.
 *  - run-stop (SessionDown/BreakerOpen/CapReached/OutsideWindow/SkippedTestMode)
 *    → hentikan tenant ini.
 *  - SkippedTemplateInactive → hentikan tenant (template tenant-wide).
 *  - SkippedOptOut → lewati satu invoice.
 * Mode Uji invoice_issued = SkippedTestMode (berhenti total, K9a) — ditangani
 * gerbang. Plafon/sesi/jendela/pemutus semuanya di gerbang (gabungan per tenant).
 */
class SendInvoicePdfAutomatically extends Command
{
    protected $signature = 'invoices:send-pdf-automatically {--dry-run : Hitung saja berapa yang akan diantre, JANGAN antre/kirim}';

    protected $description = 'Sweep kirim PDF invoice OTOMATIS ke pelanggan (v0.13.5.3.1) — gerbang CustomerAutoSendGate (v0.28.2).';

    public const TIMEZONE = 'Asia/Jakarta';

    public function handle(InvoicePdfService $pdfService, WhatsappGatewayService $gateway, CustomerAutoSendGate $gate): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = now();

        // Tenant: saklar sweep (invoice_pdf_auto_send_enabled) + saklar pelanggan
        // (customer_auto_notify_enabled) ON. Mode Uji TIDAK lagi memfilter di
        // sini (gerbang yang menanganinya → SkippedTestMode).
        $tenants = WorkOrderDispatchSettings::query()
            ->where('invoice_pdf_auto_send_enabled', true)
            ->where('customer_auto_notify_enabled', true)
            ->get();

        $totalQueued = 0;

        foreach ($tenants as $settings) {
            $totalQueued += $this->sweepTenant($settings, $pdfService, $gateway, $gate, $dryRun, $now);
        }

        $label = $dryRun ? '[dry-run] akan diantre' : 'diantre';
        $this->info("Selesai. {$totalQueued} PDF invoice {$label}.");

        return self::SUCCESS;
    }

    private function sweepTenant(
        WorkOrderDispatchSettings $settings,
        InvoicePdfService $pdfService,
        WhatsappGatewayService $gateway,
        CustomerAutoSendGate $gate,
        bool $dryRun,
        CarbonInterface $now,
    ): int {
        $tenantId = (int) $settings->tenant_id;

        if (! $dryRun) {
            Cache::put(self::lastRunCacheKey($tenantId), $now->toIso8601String(), now()->addDays(2));
        }

        // Pra-cek jendela SENYAP (tak dicatat — aturan tambahan 2): sweep
        // berhenti sebelum menyentuh pelanggan bila di luar jendela. Dry-run
        // boleh kapan saja (untuk pelaporan).
        if (! $dryRun && ! $gate->withinWindow($settings)) {
            return 0;
        }

        $lowerBound = $this->lowerBoundFor($settings);
        if ($lowerBound === null) {
            return 0;
        }

        $candidates = Invoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', InvoiceStatus::Pending->value)
            ->where('generated_at', '>=', $lowerBound)
            ->with('customer')
            ->orderBy('generated_at')
            ->get();

        $queued = 0;

        foreach ($candidates as $invoice) {
            $customer = $invoice->customer;
            if ($customer === null || $customer->status !== CustomerStatus::Aktif) {
                continue;
            }

            // Pra-skip murah (gerbang tetap otoritatif): opt-out, nomor, dedup.
            if ($customer->auto_notify_opt_out) {
                continue;
            }
            $phone = WhatsappPhone::normalize($customer->phone_number);
            if (strlen($phone) < 10 || ! str_starts_with($phone, '62')) {
                continue;
            }
            if ($this->alreadySentNonTest($invoice)) {
                continue;
            }

            if ($dryRun) {
                $queued++;

                continue;
            }

            $pdfService->store($invoice);
            $attachment = WhatsappAttachment::fromInvoicePdf($invoice, $invoice->tenant_id);

            try {
                $decision = $gateway->buildAndQueueGated(WhatsappEventType::InvoiceIssued, $customer, $invoice, $attachment);
            } catch (\Throwable $e) {
                Log::warning('SendInvoicePdfAutomatically: gagal mengantre satu invoice', [
                    'tenant_id' => $tenantId,
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($decision->queued()) {
                $queued++;

                continue;
            }

            // Template nonaktif = tenant-wide → hentikan tenant ini. Run-stop
            // (sesi/pemutus/plafon/jendela/Mode Uji) → hentikan juga.
            if ($decision->isRunStop() || $decision->result === CustomerAutoSendResult::SkippedTemplateInactive) {
                break;
            }

            // SkippedOptOut → lanjut invoice berikutnya.
        }

        return $queued;
    }

    private function alreadySentNonTest(Invoice $invoice): bool
    {
        return WhatsappMessageLog::withoutGlobalScopes()
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

    public static function lastRunCacheKey(int $tenantId): string
    {
        return "invoice_pdf_auto_send:last_run:{$tenantId}";
    }
}
