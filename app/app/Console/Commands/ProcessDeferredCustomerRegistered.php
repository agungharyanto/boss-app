<?php

namespace App\Console\Commands;

use App\Services\Whatsapp\CustomerAutoSendOrchestrator;
use Illuminate\Console\Command;

/**
 * v0.28.2 (T2) — proses penundaan notifikasi customer_registered (K11).
 *
 * v0.28.6 (penutupan cluster v0.28.0) — PEMBUNGKUS TIPIS. Menjalankan jalur
 * REGISTRATION lewat CustomerAutoSendOrchestrator + GERBANG yang sama. Manual-only
 * (titik terjadwal tunggal = whatsapp:auto-send-dispatch). Seluruh loop/evaluasi
 * deferral lama dihapus — kini di orkestrator (collectRegistration + siklus
 * hidup deferral: sent/simulated/deferred/expired/cancelled) & gerbang. Opsi
 * `--limit` lama dibuang: pekerjaan kini dibatasi plafon harian gabungan di
 * gerbang (bukan limit per-run yang kasar). --dry-run ditambahkan (preview).
 */
class ProcessDeferredCustomerRegistered extends Command
{
    protected $signature = 'whatsapp:process-deferred-registered {--dry-run : Hitung kandidat yang akan dicoba ke gerbang, JANGAN antre/kirim}';

    protected $description = 'Evaluasi ulang penundaan notifikasi customer_registered (jalur REGISTRATION) lewat orkestrator.';

    public function handle(CustomerAutoSendOrchestrator $orchestrator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $n = $orchestrator->runPath(CustomerAutoSendOrchestrator::PATH_REGISTRATION, $dryRun);

        $label = $dryRun ? '[dry-run] akan diantre' : 'diantrikan';
        $this->info("Selesai. {$n} notifikasi registrasi {$label}.");

        return self::SUCCESS;
    }
}
