<?php

namespace App\Console\Commands;

use App\Services\Whatsapp\CustomerAutoSendOrchestrator;
use Illuminate\Console\Command;

/**
 * v0.28.3 (T3, B3) — titik terjadwal TUNGGAL untuk kirim otomatis pelanggan.
 * Dijadwalkan ->everyMinute()->withoutOverlapping() (routes/console.php),
 * MENGGANTIKAN empat jalur terjadwal lama (whatsapp:send-due-reminders,
 * whatsapp:send-suspended-reminders, invoices:send-pdf-automatically,
 * whatsapp:process-deferred-registered — keempatnya kini manual-only, dicabut
 * dari schedule).
 *
 * Seluruh logika di CustomerAutoSendOrchestrator (service layer). Jendela jam,
 * plafon gabungan, prioritas K13, dedup, pre-skip opt-out, siklus hidup
 * deferral registrasi (termasuk kadaluwarsa 48 jam) — semua di sana; command
 * ini tipis.
 */
class WhatsappAutoSendDispatch extends Command
{
    protected $signature = 'whatsapp:auto-send-dispatch';

    protected $description = 'Orkestrator kirim otomatis pelanggan: kumpulkan semua jalur, urutkan prioritas K13, lewatkan ke gerbang (v0.28.3).';

    public function handle(CustomerAutoSendOrchestrator $orchestrator): int
    {
        $queued = $orchestrator->dispatchAllTenants();

        $this->info("Selesai. {$queued} pesan otomatis pelanggan diantrikan.");

        return self::SUCCESS;
    }
}
