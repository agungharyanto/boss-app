<?php

namespace App\Console\Commands;

use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Enums\WhatsappEventType;
use App\Enums\WhatsappSessionStatus;
use App\Models\Invoice;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappSession;
use App\Models\WorkOrderDispatchSettings;
use App\Services\Billing\InvoicePdfService;
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
 * Dijadwalkan `->everyFifteenMinutes()->withoutOverlapping()` (routes/console.php).
 * JALUR OTOMATIS: memanggil `WhatsappGatewayService::buildAndQueue(..., attachment,
 * context: null)` — konteks kirim-manual (`context`) dibiarkan null, jadi TETAP
 * ber-guard saklar `customer_auto_notify_enabled` (dijaga oleh test arsitektur
 * yang memastikan file ini tak pernah menyebut kelas konteks bypass itu).
 * JANGAN memakai `InvoicePdfSendService::sendManually()` (itu membangun konteks
 * bypass + redirect Mode Uji) — selalu lewat `buildAndQueue(..., null)`.
 *
 * Urutan gate (semua early-exit):
 *  1. Jam WIB ∈ [08:00, 20:00) — anti kirim larut malam (kecuali --dry-run).
 *  2. Per tenant: `invoice_pdf_auto_send_enabled` AND `customer_auto_notify_enabled`
 *     AND NOT `invoice_pdf_test_mode_enabled` (Mode Uji ON = BERHENTI TOTAL).
 *  3. Cap harian per sesi tujuan: hitung log `invoice_issued` NON-UJI hari ini WIB.
 *  4. Pilih invoice: status Pending (re-cek), `generated_at >= batas bawah`,
 *     pelanggan aktif + nomor valid, sesi tujuan connected, belum ada log
 *     `invoice_issued` non-uji; limit = cap - sent_today; urut generated_at asc.
 *
 * Pacing anti-ban ditangani worker (`SendWhatsappMessageJob` menyisipkan jeda
 * 5-10s per pesan + FIFO single-worker per queue) — command TIDAK sleep().
 */
class SendInvoicePdfAutomatically extends Command
{
    protected $signature = 'invoices:send-pdf-automatically {--dry-run : Hitung saja berapa yang akan diantre, JANGAN antre/kirim}';

    protected $description = 'Sweep kirim PDF invoice OTOMATIS ke pelanggan (v0.13.5.3.1) — gate saklar ganda, Mode Uji off, jendela 08:00-20:00 WIB, cap harian per sesi.';

    /** Jendela kirim (jam WIB), end eksklusif. */
    public const WINDOW_START_HOUR = 8;

    public const WINDOW_END_HOUR = 20;

    /** Zona waktu acuan jendela + "hari ini" — eksplisit, tak bergantung app tz. */
    public const TIMEZONE = 'Asia/Jakarta';

    public function handle(InvoicePdfService $pdfService, WhatsappGatewayService $gateway): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = now();
        $nowWib = $now->copy()->setTimezone(self::TIMEZONE);

        // Gate 1 — jendela jam WIB (jalur nyata saja; dry-run boleh kapan saja
        // untuk test/pelaporan).
        if (! $dryRun && ($nowWib->hour < self::WINDOW_START_HOUR || $nowWib->hour >= self::WINDOW_END_HOUR)) {
            return self::SUCCESS;
        }

        // Gate 2 — tenant yang ketiga saklarnya lolos.
        $tenants = WorkOrderDispatchSettings::query()
            ->where('invoice_pdf_auto_send_enabled', true)
            ->where('customer_auto_notify_enabled', true)
            ->where('invoice_pdf_test_mode_enabled', false)
            ->get();

        $totalQueued = 0;

        foreach ($tenants as $settings) {
            $totalQueued += $this->sweepTenant($settings, $pdfService, $gateway, $dryRun, $now);
        }

        $label = $dryRun ? '[dry-run] akan diantre' : 'diantre';
        $this->info("Selesai. {$totalQueued} PDF invoice {$label}.");

        return self::SUCCESS;
    }

    private function sweepTenant(
        WorkOrderDispatchSettings $settings,
        InvoicePdfService $pdfService,
        WhatsappGatewayService $gateway,
        bool $dryRun,
        CarbonInterface $now,
    ): int {
        $tenantId = (int) $settings->tenant_id;

        if (! $dryRun) {
            // Penanda "terakhir jalan" untuk indikator UI (bukan dari log WA).
            Cache::put(self::lastRunCacheKey($tenantId), $now->toIso8601String(), now()->addDays(2));
        }

        $lowerBound = $this->lowerBoundFor($settings);
        if ($lowerBound === null) {
            return 0; // saklar ON tapi tak ada batas bawah — jangan kirim apa pun.
        }

        $cap = max(1, (int) $settings->invoice_pdf_auto_send_daily_cap);

        // Baseline kuota per sesi tujuan (reseller_id null = 'direct').
        $sentToday = []; // sessionKey => int (baseline + yang baru diantre run ini)

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
            if ($customer === null) {
                continue;
            }

            // Pelanggan aktif + nomor valid.
            if ($customer->status !== CustomerStatus::Aktif) {
                continue;
            }
            $phone = WhatsappPhone::normalize($customer->phone_number);
            if (strlen($phone) < 10 || ! str_starts_with($phone, '62')) {
                continue;
            }

            // Sesi tujuan connected.
            $sessionKey = WhatsappSession::sessionKeyFor($customer->reseller_id);
            if (! $this->sessionConnected($tenantId, $customer->reseller_id)) {
                continue;
            }

            // Dedup: belum ada log invoice_issued NON-UJI untuk invoice ini.
            $alreadySent = WhatsappMessageLog::withoutGlobalScopes()
                ->where('invoice_id', $invoice->id)
                ->where('event_type', WhatsappEventType::InvoiceIssued->value)
                ->where('is_test', false)
                ->exists();
            if ($alreadySent) {
                continue;
            }

            // Cap per sesi.
            if (! array_key_exists($sessionKey, $sentToday)) {
                $sentToday[$sessionKey] = $this->baselineSentToday($tenantId, $customer->reseller_id, $now);
            }
            if ($sentToday[$sessionKey] >= $cap) {
                continue; // sesi ini penuh — sisanya lanjut run/hari berikutnya.
            }

            if ($dryRun) {
                $sentToday[$sessionKey]++;
                $queued++;

                continue;
            }

            try {
                $pdfService->store($invoice);
                $attachment = WhatsappAttachment::fromInvoicePdf($invoice, $invoice->tenant_id);

                // JALUR OTOMATIS: context: null → tetap ber-guard
                // customer_auto_notify_enabled; lampiran PDF + caption template.
                $log = $gateway->buildAndQueue(
                    WhatsappEventType::InvoiceIssued,
                    $customer,
                    $invoice,
                    $attachment,
                    null,
                );

                if ($log === null) {
                    // Template invoice_issued nonaktif/belum ada — hentikan tenant
                    // ini (tak ada gunanya mencoba invoice lain), log sekali.
                    Log::info('SendInvoicePdfAutomatically: template invoice_issued tidak aktif, tenant dilewati', [
                        'tenant_id' => $tenantId,
                    ]);
                    break;
                }

                $sentToday[$sessionKey]++;
                $queued++;
            } catch (\Throwable $e) {
                // Satu invoice gagal tidak menghentikan sisanya.
                Log::warning('SendInvoicePdfAutomatically: gagal mengantre satu invoice', [
                    'tenant_id' => $tenantId,
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $queued;
    }

    /**
     * Batas bawah "hanya invoice baru": from-date (start of day) bila diisi,
     * jika kosong = enabled_at. Null = tak ada batas (saklar ON tanpa
     * enabled_at — jangan kirim).
     */
    private function lowerBoundFor(WorkOrderDispatchSettings $settings): ?CarbonInterface
    {
        if ($settings->invoice_pdf_auto_send_from !== null) {
            return $settings->invoice_pdf_auto_send_from->copy()->startOfDay();
        }

        return $settings->invoice_pdf_auto_send_enabled_at;
    }

    private function baselineSentToday(int $tenantId, ?int $resellerId, CarbonInterface $now): int
    {
        $wibDate = $now->copy()->setTimezone(self::TIMEZONE)->toDateString();

        return WhatsappMessageLog::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('event_type', WhatsappEventType::InvoiceIssued->value)
            ->where('is_test', false)
            ->when($resellerId === null, fn ($q) => $q->whereNull('reseller_id'))
            ->when($resellerId !== null, fn ($q) => $q->where('reseller_id', $resellerId))
            ->whereDate('created_at', $wibDate)
            ->count();
    }

    private function sessionConnected(int $tenantId, ?int $resellerId): bool
    {
        $session = WhatsappSession::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($resellerId === null, fn ($q) => $q->whereNull('reseller_id'))
            ->when($resellerId !== null, fn ($q) => $q->where('reseller_id', $resellerId))
            ->first();

        return $session !== null && $session->status === WhatsappSessionStatus::Connected;
    }

    public static function lastRunCacheKey(int $tenantId): string
    {
        return "invoice_pdf_auto_send:last_run:{$tenantId}";
    }
}
