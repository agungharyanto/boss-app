<?php

namespace App\Console\Commands;

use App\Models\CustomerAutoSendDecisionLog;
use Illuminate\Console\Command;

/**
 * v0.28.2 (T2) — prune jejak keputusan gerbang > 30 hari (operasional, bukan
 * append-only). Dijadwalkan harian.
 */
class PruneCustomerAutoSendDecisions extends Command
{
    protected $signature = 'whatsapp:prune-auto-send-decisions {--days=30 : Hapus baris lebih tua dari N hari}';

    protected $description = 'Hapus baris customer_auto_send_decisions lebih tua dari N hari (default 30, v0.28.2).';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $deleted = CustomerAutoSendDecisionLog::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Prune keputusan gerbang: {$deleted} baris dihapus (< {$days} hari).");

        return self::SUCCESS;
    }
}
