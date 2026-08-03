<?php

namespace App\Console\Commands;

use App\Services\Whatsapp\WhatsappSessionService;
use Illuminate\Console\Command;

/**
 * Scheduled ->hourly() (see routes/console.php). Pulls GET /sessions from
 * the Node gateway and reconciles whatsapp_sessions — a safety net for any
 * connection.update webhook the Node service failed to deliver. The actual
 * "persistent alert until reconnect" banner is a live read of
 * whatsapp_sessions.status by the Livewire UI, not something this command
 * writes anywhere itself.
 */
class WhatsappCheckSessionHealth extends Command
{
    protected $signature = 'whatsapp:check-session-health';

    protected $description = 'Cek dan sinkronkan status koneksi semua sesi WhatsApp dari Node gateway';

    public function handle(WhatsappSessionService $service): int
    {
        $service->reconcileFromGateway();

        $this->info('Pengecekan status sesi WhatsApp selesai.');

        return self::SUCCESS;
    }
}
