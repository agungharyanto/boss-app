<?php

namespace App\Console\Commands;

use App\Models\WhatsappSession;
use Illuminate\Console\Command;

/**
 * Prints a comma-separated queue name list for `php artisan queue:work
 * --queue=$(...)` — dynamic per-session queue names (whatsapp-{session_key})
 * can't be known ahead of time the way a static --queue flag needs. See
 * boss-whatsapp-worker's entrypoint in docker-compose.yml: it re-runs this
 * command and restarts queue:work every 5 minutes so a newly created
 * reseller session's queue gets picked up without a manual container
 * restart, same polling-loop style as boss-scheduler.
 */
class WhatsappQueueNames extends Command
{
    protected $signature = 'whatsapp:queue-names';

    protected $description = 'Cetak daftar nama queue WhatsApp (whatsapp-{session_key}) yang sedang aktif, dipisah koma';

    public function handle(): int
    {
        $sessionKeys = WhatsappSession::withoutGlobalScopes()
            ->get()
            ->map(fn (WhatsappSession $session) => $session->sessionKey())
            ->push('direct')
            ->unique()
            ->values();

        $queues = $sessionKeys->map(fn (string $key) => "whatsapp-{$key}")->implode(',');

        $this->output->write($queues);

        return self::SUCCESS;
    }
}
