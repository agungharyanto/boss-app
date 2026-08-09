<?php

namespace App\Console\Commands;

use App\Services\Network\CpeBindingService;
use Illuminate\Console\Command;

/**
 * Scheduled ->everyFiveMinutes() (see routes/console.php) — standard
 * reconciliation-loop shape for the "device was bound before its first
 * real TR-069 Inform" case (a CpeDevice created via CpeBindingService::
 * bindFromWorkOrder() while GenieACS had never heard of that serial number
 * yet, so it was left `pending_first_connect`). Every real CpeDevice row
 * eventually gets picked up here once the physical device actually powers
 * on and dials genieacs-cwmp for the first time.
 */
class ReconcileCpeDevices extends Command
{
    protected $signature = 'cpe:reconcile';

    protected $description = 'Cocokkan ulang CpeDevice berstatus pending_first_connect terhadap GenieACS';

    public function handle(CpeBindingService $service): int
    {
        $reconciled = $service->reconcilePending();

        if ($reconciled > 0) {
            $this->info("{$reconciled} CpeDevice ter-reconcile dari pending_first_connect.");
        }

        return self::SUCCESS;
    }
}
