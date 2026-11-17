<?php

namespace App\Console\Commands;

use App\Services\Whatsapp\WhatsappIncomingMessageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * v0.13.6 — RECONCILE media masuk: tutup webhook incoming-message yang HILANG
 * (fire-and-forget, tanpa retry di sisi Go). Memindai sidecar JSON yang ditulis
 * gateway ke volume bersama; baris yang belum ada di DB dimasukkan (idempoten
 * via message_id UNIQUE). Laravel HANYA MEMBACA volume — tidak pernah menghapus
 * file di sana (pembersihan = tugas gateway, retensi pendek).
 *
 * Dijadwalkan ->everyFiveMinutes()->withoutOverlapping() (routes/console.php).
 */
class WhatsappReconcileIncomingMedia extends Command
{
    protected $signature = 'whatsapp:reconcile-incoming-media';

    protected $description = 'Masukkan sidecar media masuk dari volume gateway yang webhook-nya hilang (idempoten).';

    public function handle(WhatsappIncomingMessageService $service): int
    {
        $baseDir = rtrim((string) config('services.whatsapp_gateway.incoming_media_dir'), '/');

        if ($baseDir === '' || ! is_dir($baseDir)) {
            // Volume belum ter-mount (mis. lingkungan dev tanpa gateway) — bukan error.
            return self::SUCCESS;
        }

        $sidecars = glob($baseDir.'/*/*.json') ?: [];
        $inserted = 0;

        foreach ($sidecars as $path) {
            $raw = @file_get_contents($path);
            if ($raw === false) {
                continue;
            }

            $data = json_decode($raw, true);
            if (! is_array($data)) {
                Log::warning("WhatsappReconcileIncomingMedia: sidecar tidak valid JSON — {$path}");

                continue;
            }

            try {
                if ($service->recordFromSidecar($data)) {
                    $inserted++;
                }
            } catch (\Throwable $e) {
                Log::warning('WhatsappReconcileIncomingMedia: gagal memproses sidecar', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($inserted > 0) {
            $this->info("Reconcile selesai. {$inserted} media masuk ditambahkan dari sidecar.");
        }

        return self::SUCCESS;
    }
}
