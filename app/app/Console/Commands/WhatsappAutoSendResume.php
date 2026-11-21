<?php

namespace App\Console\Commands;

use App\Models\CustomerAutoSendBreaker;
use App\Models\Tenant;
use App\Services\Whatsapp\CustomerAutoSendGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * v0.28.2 (T2) — lanjut MANUAL kirim otomatis (tutup pemutus) untuk sebuah
 * tenant. Dipakai sebelum ada tombol "Lanjutkan" di UI (T4). Mencatat
 * siapa/kapan (CLAUDE.md: artisan dijalankan sebagai www-data; `--by=` opsional
 * untuk id user pelaku).
 */
class WhatsappAutoSendResume extends Command
{
    protected $signature = 'whatsapp:auto-send-resume {tenant : ID tenant} {--by= : ID user pelaku (opsional)}';

    protected $description = 'Lanjutkan kirim otomatis (tutup pemutus) untuk semua sesi sebuah tenant (v0.28.2).';

    public function handle(CustomerAutoSendGate $gate): int
    {
        $tenantId = (int) $this->argument('tenant');
        if (Tenant::query()->whereKey($tenantId)->doesntExist()) {
            $this->error("Tenant {$tenantId} tidak ditemukan.");

            return self::FAILURE;
        }

        $userId = $this->option('by') !== null ? (int) $this->option('by') : null;

        $breakers = CustomerAutoSendBreaker::query()->where('tenant_id', $tenantId)->get();
        $resumed = 0;

        foreach ($breakers as $breaker) {
            if (! $breaker->is_open && $breaker->consecutive_failures === 0) {
                continue;
            }

            $resellerId = $breaker->session_key === 'direct' ? null : (int) $breaker->session_key;
            $gate->resume($tenantId, $resellerId, $userId);
            $resumed++;
        }

        Log::info('whatsapp:auto-send-resume dijalankan', [
            'tenant_id' => $tenantId,
            'by_user_id' => $userId,
            'sesi_dilanjutkan' => $resumed,
        ]);

        $this->info("Pemutus dilanjutkan untuk {$resumed} sesi (tenant {$tenantId}).");

        return self::SUCCESS;
    }
}
