<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Whatsapp\CustomerAutoSendSettingsService;
use Illuminate\Console\Command;

/**
 * v0.28.2 (T2) — lanjut MANUAL kirim otomatis (tutup pemutus) untuk sebuah
 * tenant. Mesin resume-nya kini di CustomerAutoSendSettingsService::resumeAll()
 * (v0.28.5) — command DAN tombol "Lanjutkan" di tab Kirim Otomatis memakai
 * mesin yang SAMA. `--by=` opsional untuk id user pelaku (CLAUDE.md: artisan
 * dijalankan sebagai www-data).
 */
class WhatsappAutoSendResume extends Command
{
    protected $signature = 'whatsapp:auto-send-resume {tenant : ID tenant} {--by= : ID user pelaku (opsional)}';

    protected $description = 'Lanjutkan kirim otomatis (tutup pemutus) untuk semua sesi sebuah tenant (v0.28.2).';

    public function handle(CustomerAutoSendSettingsService $service): int
    {
        $tenantId = (int) $this->argument('tenant');
        if (Tenant::query()->whereKey($tenantId)->doesntExist()) {
            $this->error("Tenant {$tenantId} tidak ditemukan.");

            return self::FAILURE;
        }

        $userId = $this->option('by') !== null ? (int) $this->option('by') : null;

        $resumed = $service->resumeAll($tenantId, $userId);

        $this->info("Pemutus dilanjutkan untuk {$resumed} sesi (tenant {$tenantId}).");

        return self::SUCCESS;
    }
}
