<?php

namespace App\Console\Commands;

use App\Exceptions\TechnicianTokenException;
use App\Models\Technician;
use App\Services\Installation\TechnicianTokenService;
use Illuminate\Console\Command;

/**
 * v0.12.3 — penerbitan manual token Sanctum Technician-scoped API, jalur
 * command-line (mis. dijalankan admin lewat SSH). Logic sama persis
 * dengan `POST /technicians/{technician}/token` — lihat
 * TechnicianTokenService.
 */
class GenerateTechnicianToken extends Command
{
    protected $signature = 'technician:token {technician_id : ID baris technicians}';

    protected $description = 'Terbitkan token Sanctum baru untuk Technician (revoke semua token lama miliknya)';

    public function handle(TechnicianTokenService $service): int
    {
        $technician = Technician::withoutGlobalScopes()->find((int) $this->argument('technician_id'));

        if ($technician === null) {
            $this->error('Technician dengan id tersebut tidak ditemukan.');

            return self::FAILURE;
        }

        try {
            $token = $service->generate($technician);
        } catch (TechnicianTokenException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Token baru untuk \"{$technician->name}\" (#{$technician->id}):");
        $this->line($token);
        $this->warn('Simpan token ini sekarang — tidak akan ditampilkan lagi.');

        return self::SUCCESS;
    }
}
