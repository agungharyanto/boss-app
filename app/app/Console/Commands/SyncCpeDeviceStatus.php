<?php

namespace App\Console\Commands;

use App\Services\Network\CpeDeviceStatusSyncService;
use Illuminate\Console\Command;

/**
 * Scheduled ->everyFifteenMinutes() (see routes/console.php), same cadence
 * as cpe:auto-match-legacy-devices — refreshes online/offline status for
 * every already-known CpeDevice, closing the gap where status/last_inform_at
 * were only ever set once, at bind/reconcile time (see
 * App\Services\Network\CpeDeviceStatusSyncService's own docblock).
 */
class SyncCpeDeviceStatus extends Command
{
    protected $signature = 'cpe:sync-device-status';

    protected $description = 'Sinkronkan status online/offline dan last_inform_at semua CpeDevice yang sudah dikenal GenieACS';

    public function handle(CpeDeviceStatusSyncService $service): int
    {
        $result = $service->syncAll();

        $this->info("Synced: {$result['synced']} | Online: {$result['online']} | Offline: {$result['offline']} | Skipped: {$result['skipped']}");

        return self::SUCCESS;
    }
}
