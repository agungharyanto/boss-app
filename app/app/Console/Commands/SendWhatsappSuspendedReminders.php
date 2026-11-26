<?php

namespace App\Console\Commands;

use App\Services\Whatsapp\CustomerAutoSendOrchestrator;
use Illuminate\Console\Command;

/**
 * v0.28.6 (penutupan cluster v0.28.0) — PEMBUNGKUS TIPIS. Menjalankan jalur
 * SUSPEND (pengingat harian pelanggan suspend) lewat CustomerAutoSendOrchestrator
 * + GERBANG yang sama. Manual-only (titik terjadwal tunggal =
 * whatsapp:auto-send-dispatch). Seluruh query/dedup lama dihapus — kini di
 * orkestrator (collectSuspend) & gerbang.
 */
class SendWhatsappSuspendedReminders extends Command
{
    protected $signature = 'whatsapp:send-suspended-reminders {--dry-run : Hitung kandidat yang akan dicoba ke gerbang, JANGAN antre/kirim}';

    protected $description = 'Pengingat suspend (jalur SUSPEND) lewat orkestrator kirim otomatis pelanggan.';

    public function handle(CustomerAutoSendOrchestrator $orchestrator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $n = $orchestrator->runPath(CustomerAutoSendOrchestrator::PATH_SUSPEND, $dryRun);

        $label = $dryRun ? '[dry-run] akan diantre' : 'diantrikan';
        $this->info("Selesai. {$n} pengingat suspend {$label}.");

        return self::SUCCESS;
    }
}
