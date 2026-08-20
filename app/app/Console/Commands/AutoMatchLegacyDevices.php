<?php

namespace App\Console\Commands;

use App\Services\Network\LegacyDeviceMatcherService;
use Illuminate\Console\Command;

/**
 * Scheduled ->everyFifteenMinutes() (see routes/console.php) — deliberately
 * slower than ReconcileCpeDevices'/SyncCpeConnectedHosts' 5-minute cadence:
 * this scans and hex-compares every unbound GenieACS device against the
 * whole legacy_mac_customer_map on every run, heavier work than either of
 * those, and matching a legacy device to its customer isn't as
 * time-sensitive as reconciling a device that's mid-installation right now.
 */
class AutoMatchLegacyDevices extends Command
{
    protected $signature = 'cpe:auto-match-legacy-devices';

    protected $description = 'Cocokkan device GenieACS yang belum ter-bind ke customer legacy MixRadius lewat referensi MAC';

    public function handle(LegacyDeviceMatcherService $service): int
    {
        $bound = $service->matchAndBind();

        $this->info("{$bound} device berhasil di-bind otomatis.");

        return self::SUCCESS;
    }
}
