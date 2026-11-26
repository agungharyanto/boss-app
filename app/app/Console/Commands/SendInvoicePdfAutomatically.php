<?php

namespace App\Console\Commands;

use App\Services\Whatsapp\CustomerAutoSendOrchestrator;
use Illuminate\Console\Command;

/**
 * v0.13.5.3.1 — sweep kirim PDF invoice OTOMATIS ke pelanggan.
 *
 * v0.28.6 (penutupan cluster v0.28.0) — PEMBUNGKUS TIPIS. Menjalankan jalur
 * INVOICE lewat CustomerAutoSendOrchestrator + GERBANG yang sama. Manual-only
 * (titik terjadwal tunggal = whatsapp:auto-send-dispatch). Seluruh query/
 * pra-skip/dedup/window/cap lama dihapus — kini di orkestrator (collectInvoice)
 * & gerbang. --dry-run dipertahankan (hitung kandidat yang akan dicoba).
 */
class SendInvoicePdfAutomatically extends Command
{
    protected $signature = 'invoices:send-pdf-automatically {--dry-run : Hitung saja berapa yang akan diantre, JANGAN antre/kirim}';

    protected $description = 'Sweep kirim PDF invoice OTOMATIS ke pelanggan (jalur INVOICE) lewat orkestrator kirim otomatis pelanggan.';

    public function handle(CustomerAutoSendOrchestrator $orchestrator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $n = $orchestrator->runPath(CustomerAutoSendOrchestrator::PATH_INVOICE, $dryRun);

        $label = $dryRun ? '[dry-run] akan diantre' : 'diantre';
        $this->info("Selesai. {$n} PDF invoice {$label}.");

        return self::SUCCESS;
    }
}
