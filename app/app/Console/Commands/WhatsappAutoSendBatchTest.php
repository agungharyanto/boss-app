<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\WhatsappEventType;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\WhatsappGatewaySettings;
use App\Models\WhatsappMessageTemplate;
use App\Models\WhatsappSession;
use App\Models\WorkOrderDispatchSettings;
use App\Services\Billing\InvoicePdfService;
use App\Services\Whatsapp\CustomerAutoSendGate;
use App\Services\Whatsapp\CustomerAutoSendOrchestrator;
use App\Services\Whatsapp\WhatsappGatewayService;
use App\Support\Whatsapp\WhatsappAttachment;
use App\Support\WhatsappPhone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * v0.28.4 (SEBAR-BATCH, A6) — uji LIVE jeda-batch TANPA risiko ke pelanggan
 * nyata. Simulasi (Mode Uji) tak meng-queue apa pun, jadi membuktikan ->delay
 * batch butuh kirim SUNGGUHAN — command ini membatasinya HANYA ke pelanggan
 * berlabel TEST yang nomornya === penerima uji tenant (6287884374939).
 *
 * DIBANGUN SEKARANG, DIJALANKAN NANTI atas perintah terpisah. Pagar berlapis:
 *  - --confirm WAJIB.
 *  - setiap --customer wajib name LIKE 'TEST%' DAN normalize(phone) === penerima
 *    uji tenant; satu saja gagal → ABORT, nol queue.
 *  - prasyarat keras: template due/suspend/registered NONAKTIF; auto_send OFF;
 *    sesi Connected; di dalam jendela.
 * Selama uji (try/finally JAMIN pulih walau exception): flag jeda orkestrator
 * ON (orkestrator tak kumpulkan kandidat NYATA), batch_size/pause kecil, Mode
 * Uji OFF, auto_notify ON. Lalu queue invoice_issued TEST lewat gerbang yang
 * SAMA (kursor + delay).
 *
 * OPSI (a) — command TIDAK menunggu "sent". Setelah antre, ia membaca skor
 * (available_at, epoch) tiap job yang BENAR-BENAR ada di Redis delayed set
 * queue whatsapp-{session_key} dan mencetak tabel (log id, NOMOR INVOICE,
 * delay terhitung, available_at). Lalu `finally` memulihkan saklar — termasuk
 * auto_notify kembali OFF. Job ter-delay akan DIBATALKAN oleh recheck (D4)
 * saat jatuh tempo (saklar sudah OFF lagi): itu perilaku yang DIHARAPKAN dan
 * ikut dibuktikan terpisah dengan menunggu status log jadi 'cancelled'.
 *
 * TEST invoice/subscription yang dibuat SENGAJA dibiarkan (job async masih
 * butuh file PDF-nya) — id dicetak untuk dibersihkan terpisah.
 */
class WhatsappAutoSendBatchTest extends Command
{
    protected $signature = 'whatsapp:auto-send-batch-test
        {--customer=* : id pelanggan TEST (boleh banyak)}
        {--confirm : wajib — tanpa ini command menolak jalan}
        {--count=5 : jumlah invoice TEST per pelanggan}
        {--batch-size=3 : rate_limit_batch_size sementara}
        {--pause-min=1 : pause min (menit) sementara}
        {--pause-max=2 : pause max (menit) sementara}';

    protected $description = 'Uji LIVE jeda-batch kirim otomatis (TEST-only, hanya ke nomor uji tenant).';

    public function handle(
        CustomerAutoSendGate $gate,
        WhatsappGatewayService $gateway,
        InvoicePdfService $pdfService,
    ): int {
        if (! $this->option('confirm')) {
            $this->error('WAJIB --confirm. Command ini mengirim WhatsApp SUNGGUHAN ke nomor uji. Abort.');

            return self::FAILURE;
        }

        $ids = array_map('intval', (array) $this->option('customer'));
        if ($ids === []) {
            $this->error('Tidak ada --customer. Abort.');

            return self::FAILURE;
        }

        $count = max(1, (int) $this->option('count'));
        $batchSize = max(1, (int) $this->option('batch-size'));
        $pauseMin = max(1, (int) $this->option('pause-min'));
        $pauseMax = max($pauseMin, (int) $this->option('pause-max'));

        // Batas waktu maks 20 menit: paling banyak 2 batas batch dilewati.
        $boundaries = intdiv(($count * count($ids)) - 1, $batchSize);
        if ($boundaries * $pauseMax > 20) {
            $this->error("Proyeksi rentang uji {$boundaries}×{$pauseMax} mnt > 20 mnt. Kecilkan count/pause. Abort.");

            return self::FAILURE;
        }

        // ── Pagar pelanggan: TEST-only + nomor === penerima uji tenant ──────
        $customers = Customer::withoutGlobalScopes()->whereIn('id', $ids)->get();
        if ($customers->count() !== count(array_unique($ids))) {
            $this->error('Sebagian id pelanggan tidak ditemukan. Abort, nol queue.');

            return self::FAILURE;
        }

        $tenantIds = $customers->pluck('tenant_id')->unique();
        if ($tenantIds->count() !== 1) {
            $this->error('Semua pelanggan harus satu tenant. Abort.');

            return self::FAILURE;
        }
        $tenantId = (int) $tenantIds->first();
        $settings = WorkOrderDispatchSettings::forTenant($tenantId);

        $testRecipient = WhatsappPhone::normalize((string) $settings->invoice_pdf_test_recipient);
        if (strlen($testRecipient) < 10) {
            $this->error('Penerima uji tenant (invoice_pdf_test_recipient) belum di-set valid. Abort.');

            return self::FAILURE;
        }

        foreach ($customers as $c) {
            if (! str_starts_with((string) $c->name, 'TEST')) {
                $this->error("Pelanggan #{$c->id} ('{$c->name}') BUKAN berlabel TEST. Abort, nol queue.");

                return self::FAILURE;
            }
            if (WhatsappPhone::normalize((string) $c->phone_number) !== $testRecipient) {
                $this->error("Nomor pelanggan #{$c->id} bukan nomor uji tenant ({$testRecipient}). Abort, nol queue.");

                return self::FAILURE;
            }
        }

        // ── Prasyarat keras ─────────────────────────────────────────────────
        $inactiveTemplates = [
            WhatsappEventType::InvoiceDueReminder->value,
            WhatsappEventType::CustomerSuspendedReminder->value,
            WhatsappEventType::CustomerRegistered->value,
        ];
        $activeAmong = WhatsappMessageTemplate::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('reseller_id')
            ->whereIn('event_type', $inactiveTemplates)
            ->where('is_active', true)
            ->pluck('event_type');
        if ($activeAmong->isNotEmpty()) {
            $this->error('Template due/suspend/registered harus NONAKTIF semua. Masih aktif: '.$activeAmong->implode(', ').'. Abort.');

            return self::FAILURE;
        }
        if ($settings->invoice_pdf_auto_send_enabled) {
            $this->error('invoice_pdf_auto_send_enabled harus false. Abort.');

            return self::FAILURE;
        }
        $resellerId = $customers->first()->reseller_id;
        if (! $gate->sessionConnected($tenantId, $resellerId)) {
            $this->error('Sesi WhatsApp tidak Connected. Abort.');

            return self::FAILURE;
        }
        if (! $gate->withinWindow($settings)) {
            $this->error('Di luar jendela jam. Abort.');

            return self::FAILURE;
        }

        // ── Simpan nilai asli ───────────────────────────────────────────────
        $gw = WhatsappGatewaySettings::current();
        $origBatchSize = $gw->rate_limit_batch_size;
        $origPauseMin = $gw->rate_limit_batch_pause_min_minutes;
        $origPauseMax = $gw->rate_limit_batch_pause_max_minutes;
        $origTestMode = (bool) $settings->invoice_pdf_test_mode_enabled;
        $origAutoNotify = (bool) $settings->customer_auto_notify_enabled;

        $createdInvoiceIds = [];
        $createdSubscriptionIds = [];

        // Flag jeda ON SEBELUM apa pun: orkestrator (tick everyMinute) tak boleh
        // mengumpulkan kandidat NYATA selama Mode Uji dimatikan sementara.
        Cache::put(CustomerAutoSendOrchestrator::PAUSE_CACHE_KEY, true, now()->addMinutes(CustomerAutoSendOrchestrator::PAUSE_TTL_MINUTES));

        try {
            $gw->update([
                'rate_limit_batch_size' => $batchSize,
                'rate_limit_batch_pause_min_minutes' => $pauseMin,
                'rate_limit_batch_pause_max_minutes' => $pauseMax,
            ]);
            $settings->update([
                'invoice_pdf_test_mode_enabled' => false,
                'customer_auto_notify_enabled' => true,
            ]);

            $this->info("Uji jeda-batch: batch_size={$batchSize}, pause={$pauseMin}-{$pauseMax} mnt, {$count} invoice/pelanggan → nomor {$testRecipient}.");

            $n = 0;
            $queued = [];
            foreach ($customers as $customer) {
                $sub = $this->makeTestSubscription($customer);
                $createdSubscriptionIds[] = $sub->id;

                for ($i = 1; $i <= $count; $i++) {
                    $invoice = $this->makeTestInvoice($customer, $sub, $i);
                    $createdInvoiceIds[] = $invoice->id;

                    try {
                        $pdfService->store($invoice);
                        $attachment = WhatsappAttachment::fromInvoicePdf($invoice, (int) $invoice->tenant_id);
                    } catch (\Throwable $e) {
                        $this->warn("  invoice {$invoice->invoice_number}: gagal render PDF ({$e->getMessage()}) — dilewati.");

                        continue;
                    }

                    $decision = $gateway->buildAndQueueGated(
                        WhatsappEventType::InvoiceIssued,
                        $customer,
                        $invoice,
                        $attachment,
                    );

                    $n++;
                    $queued[] = [
                        'n' => $n,
                        'invoice' => $invoice->invoice_number,
                        'logId' => $decision->log?->id,
                        'result' => $decision->result->value,
                        'delay' => $decision->delaySeconds,
                        'sessionKey' => WhatsappSession::sessionKeyFor($customer->reseller_id),
                    ];
                }
            }

            $this->info("Selesai meng-queue {$n} pesan. Membaca available_at dari Redis delayed set (opsi a — command tak menunggu 'sent').");

            // ── OPSI (a): buktikan ->delay() batch mendarat di Redis ────────────
            $avail = $this->readRedisDelayedAvailableAt(array_column($queued, 'sessionKey'));
            $base = null;
            foreach ($queued as $q) {
                if ($q['logId'] !== null && isset($avail[$q['logId']])) {
                    $base = $base === null ? $avail[$q['logId']] : min($base, $avail[$q['logId']]);
                }
            }
            $rows = [];
            foreach ($queued as $q) {
                $a = ($q['logId'] !== null) ? ($avail[$q['logId']] ?? null) : null;
                $rows[] = [
                    $q['n'],
                    $q['invoice'],
                    $q['logId'] ?? '-',
                    $q['result'],
                    $q['delay'] ?? '-',
                    $a !== null ? $a : 'tidak di delayed set (due/migrasi)',
                    ($a !== null && $base !== null) ? ('+'.($a - $base).'s dari #1') : '-',
                ];
            }
            $this->table(
                ['#', 'NOMOR INVOICE', 'log id', 'result', 'delay(s)', 'available_at (epoch)', 'offset'],
                $rows,
            );
        } finally {
            // JAMIN pulih walau exception di atas.
            $gw->update([
                'rate_limit_batch_size' => $origBatchSize,
                'rate_limit_batch_pause_min_minutes' => $origPauseMin,
                'rate_limit_batch_pause_max_minutes' => $origPauseMax,
            ]);
            $settings->update([
                'invoice_pdf_test_mode_enabled' => $origTestMode,
                'customer_auto_notify_enabled' => $origAutoNotify,
            ]);
            Cache::forget(CustomerAutoSendOrchestrator::PAUSE_CACHE_KEY);

            $this->info('Dipulihkan: batch/pause, Mode Uji, auto_notify; flag jeda dilepas.');
        }

        $this->warn('TEST invoice ids: '.implode(',', $createdInvoiceIds).' — subscription ids: '.implode(',', $createdSubscriptionIds).' (bersihkan terpisah SETELAH job terkirim).');

        return self::SUCCESS;
    }

    private function makeTestSubscription(Customer $customer): Subscription
    {
        return Subscription::create([
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->id,
            'reseller_id' => $customer->reseller_id,
            'name' => 'TEST BATCH sub',
            'monthly_amount' => 0,
            'status' => SubscriptionStatus::Cancelled->value, // invisible ke GenerateDueInvoices
            'billing_cycle_day' => 1,
            'started_at' => now(),
        ]);
    }

    private function makeTestInvoice(Customer $customer, Subscription $sub, int $i): Invoice
    {
        $num = 'INV/TEST-BATCH/'.now()->format('ymdHis')."/{$customer->id}/{$i}";

        // v0.28.4 (cacat #1 fix) — tiap invoice TEST memakai periode BERBEDA
        // (bulan ke-belakang per $i) supaya kombinasi (subscription_id,
        // period_start, period_end) unik — unique constraint
        // invoices_subscription_id_period_start_period_end_unique tak dilanggar.
        $period = now()->subMonths($i - 1);

        return Invoice::create([
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->id,
            'subscription_id' => $sub->id,
            'reseller_id' => $customer->reseller_id,
            'invoice_number' => $num,
            'period_start' => $period->copy()->startOfMonth()->toDateString(),
            'period_end' => $period->copy()->endOfMonth()->toDateString(),
            'due_date' => now()->toDateString(),
            'status' => InvoiceStatus::Pending->value,
            'subtotal' => 10000,
            'tax_total' => 0,
            'grand_total' => 10000,
            'generated_at' => now(),
        ]);
    }

    /**
     * OPSI (a) — baca skor (available_at, epoch) tiap job yang BENAR-BENAR ada
     * di Redis delayed set queue whatsapp-{sessionKey}, dipetakan per logId.
     * Membuktikan ->delay() batch mendarat di Redis tanpa menunggu "sent".
     *
     * Catatan: job delay(0) mendarat dengan skor ≈ now dan bisa SEGERA dimigrasi
     * worker ke ready list; bila logId-nya tak ada di delayed set, itu konsisten
     * dengan delay≈0 (sudah jatuh tempo), bukan kegagalan.
     *
     * @param  array<int,string>  $sessionKeys
     * @return array<int,int> logId => available_at (unix epoch)
     */
    private function readRedisDelayedAvailableAt(array $sessionKeys): array
    {
        $map = [];
        try {
            $conn = Redis::connection(config('queue.connections.redis.connection'));
        } catch (\Throwable $e) {
            $this->warn("  gagal konek Redis: {$e->getMessage()}");

            return $map;
        }

        foreach (array_values(array_unique($sessionKeys)) as $key) {
            $zkey = 'queues:whatsapp-'.$key.':delayed';
            try {
                // phpredis: ['withscores' => true] → [payload(member) => score].
                $members = $conn->zrangebyscore($zkey, '-inf', '+inf', ['withscores' => true]);
            } catch (\Throwable $e) {
                $this->warn("  gagal baca Redis {$zkey}: {$e->getMessage()}");

                continue;
            }

            foreach ((array) $members as $payload => $score) {
                $decoded = json_decode((string) $payload, true);
                $command = $decoded['data']['command'] ?? '';
                // SendWhatsappMessageJob punya properti `public readonly int $logId`
                // → serialized sebagai `logId";i:<id>;`.
                if (is_string($command) && preg_match('/logId";i:(\d+)/', $command, $m)) {
                    $map[(int) $m[1]] = (int) $score;
                }
            }
        }

        return $map;
    }
}
