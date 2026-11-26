<?php

namespace App\Console\Commands;

use App\Services\Whatsapp\CustomerAutoSendOrchestrator;
use Illuminate\Console\Command;

/**
 * v0.28.6 (penutupan cluster v0.28.0) — PEMBUNGKUS TIPIS. Menjalankan jalur
 * DUE (pengingat jatuh tempo) lewat CustomerAutoSendOrchestrator + GERBANG yang
 * sama. Manual-only: titik terjadwal tunggal tetap whatsapp:auto-send-dispatch
 * (routes/console.php tidak berubah). Seluruh query/dedup/isScheduledNow lama
 * dihapus — kini di orkestrator (collectDue + due_reminder_days) & gerbang.
 */
class SendWhatsappDueReminders extends Command
{
    protected $signature = 'whatsapp:send-due-reminders {--dry-run : Hitung kandidat yang akan dicoba ke gerbang, JANGAN antre/kirim}';

    protected $description = 'Pengingat jatuh tempo (jalur DUE) lewat orkestrator kirim otomatis pelanggan.';

    public function handle(CustomerAutoSendOrchestrator $orchestrator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $n = $orchestrator->runPath(CustomerAutoSendOrchestrator::PATH_DUE, $dryRun);

        $label = $dryRun ? '[dry-run] akan diantre' : 'diantrikan';
        $this->info("Selesai. {$n} pengingat jatuh tempo {$label}.");

        return self::SUCCESS;
    }
}
