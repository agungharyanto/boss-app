<?php

namespace App\Console\Commands;

use App\Services\Infra\ContainerStatsService;
use Illuminate\Console\Command;

/**
 * Scheduled every 5 minutes, ->withoutOverlapping() (see routes/
 * console.php) — writes one App\Models\ContainerStatsHistory row per
 * container docker-stats-proxy currently reports. See
 * ContainerStatsService's own docblock for the real measured timing
 * (~53s for 27 containers on this server) that drove the 5-minute
 * interval choice.
 */
class SyncContainerStats extends Command
{
    protected $signature = 'infra:sync-container-stats';

    protected $description = 'Rekam histori CPU/Memory/Network/Disk semua container Docker (setiap 5 menit)';

    public function handle(ContainerStatsService $service): int
    {
        $result = $service->syncAll();

        $this->info("Total: {$result['total']} | Recorded: {$result['recorded']} | Failed: {$result['failed']}");

        return self::SUCCESS;
    }
}
