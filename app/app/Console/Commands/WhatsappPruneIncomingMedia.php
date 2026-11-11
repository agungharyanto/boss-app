<?php

namespace App\Console\Commands;

use App\Models\WhatsappIncomingMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * v0.13.6 — RETENSI media masuk di disk PRIVAT boss-app (D6: 90 hari). Hapus
 * FILE media + null-kan `media_file_path` untuk baris lebih tua dari ambang;
 * baris pesan TETAP ada (jejak bahwa pesan pernah masuk), hanya file-nya yang
 * dibuang. Berbeda dari retensi volume GATEWAY (7 hari, dibersihkan gateway
 * sendiri) — ini copy privat boss-app.
 */
class WhatsappPruneIncomingMedia extends Command
{
    protected $signature = 'whatsapp:prune-incoming-media {--days=90 : Umur maksimal file media (hari)}';

    protected $description = 'Hapus file media masuk di disk privat yang lebih tua dari N hari (default 90); baris pesan dipertahankan.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days < 1) {
            $days = 90;
        }

        $cutoff = now()->subDays($days);
        $pruned = 0;

        WhatsappIncomingMessage::query()
            ->whereNotNull('media_file_path')
            ->where('received_at', '<', $cutoff)
            ->chunkById(200, function ($rows) use (&$pruned) {
                foreach ($rows as $row) {
                    if ($row->media_file_path !== null) {
                        Storage::disk('local')->delete($row->media_file_path);
                    }
                    $row->update(['media_file_path' => null]);
                    $pruned++;
                }
            });

        if ($pruned > 0) {
            $this->info("Prune selesai. {$pruned} file media privat dihapus (baris dipertahankan).");
        }

        return self::SUCCESS;
    }
}
