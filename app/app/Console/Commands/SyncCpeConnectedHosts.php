<?php

namespace App\Console\Commands;

use App\Enums\CpeDeviceStatus;
use App\Models\CpeDevice;
use App\Services\Network\CpeConnectedHostsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled (see routes/console.php) — same polling-loop shape as
 * ReconcileCpeDevices. Only iterates `online` CpeDevice rows (a `pending_
 * first_connect`/`offline` device has no genieacs_device_id worth reading,
 * or is by definition not currently reachable) — CpeConnectedHostsService
 * itself also no-ops safely if genieacs_device_id is null, this is just to
 * avoid pointless GenieACS queries for devices that can't have anything new.
 * One device's failure (a bad tree shape, a transient genieacs-nbi error)
 * never stops the rest of the loop.
 */
class SyncCpeConnectedHosts extends Command
{
    protected $signature = 'cpe:sync-connected-hosts';

    protected $description = 'Sinkronkan daftar client TR-069 (Hosts.Host) untuk semua CpeDevice online';

    public function handle(CpeConnectedHostsService $service): int
    {
        $devices = CpeDevice::withoutGlobalScopes()
            ->where('status', CpeDeviceStatus::Online)
            ->get();

        $synced = 0;

        foreach ($devices as $device) {
            try {
                $service->syncFromGenieAcs($device);
                $synced++;
            } catch (Throwable $e) {
                Log::warning("SyncCpeConnectedHosts: gagal sync untuk CpeDevice #{$device->id} — {$e->getMessage()}");
            }
        }

        $this->info("{$synced}/{$devices->count()} CpeDevice online berhasil disinkronkan.");

        return self::SUCCESS;
    }
}
