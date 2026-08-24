<?php

namespace App\Console\Commands;

use App\Services\Network\CpeSignalHistoryService;
use Illuminate\Console\Command;

/**
 * Scheduled every 20 minutes, ->withoutOverlapping() (see routes/
 * console.php) — writes one App\Models\CpeSignalHistory row per online
 * CpeDevice with a catalogued RX power parameter. A deliberately separate
 * command/service from cpe:sync-device-status (SyncCpeDeviceStatus /
 * CpeDeviceStatusSyncService, v0.7.7) — see CpeSignalHistoryService's own
 * docblock for why sharing one wasn't the right call. This run genuinely
 * takes several minutes (staggered GenieACS refreshObject sends + a single
 * read-back wait) — that's expected, not a bug, see the service's own
 * worked-example runtime math.
 */
class SyncCpeSignalHistory extends Command
{
    protected $signature = 'cpe:sync-signal-history';

    protected $description = 'Rekam histori RX Power untuk semua CpeDevice online (setiap 20 menit)';

    public function handle(CpeSignalHistoryService $service): int
    {
        $result = $service->syncAll();

        $this->info(
            "Online: {$result['total_online']} | Recorded: {$result['recorded']} | ".
            "Failed (null): {$result['failed']} | Skipped (no catalog): {$result['skipped']}"
        );

        return self::SUCCESS;
    }
}
