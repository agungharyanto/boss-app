<?php

namespace App\Console\Commands;

use App\Models\LegacyMacCustomerMap;
use Illuminate\Console\Command;

/**
 * One-time bulk load of MixRadius' own radacct-derived MAC-to-username
 * reference (already filtered to real customers before this file was
 * handed over — no voucher/hotspot rows). Plain insert, no matching logic
 * here — App\Services\Network\LegacyDeviceMatcherService is the only reader
 * and does all the actual matching. Not idempotent by design (a second run
 * against the same file would duplicate rows) — this is meant to be run
 * once per reference export, same posture as
 * App\Console\Commands\ImportLegacyCpeBindings's own CSV.
 */
class ImportLegacyMacReference extends Command
{
    protected $signature = 'legacy-mac-map:import {path : Path to mac_reference.csv}';

    protected $description = 'Load referensi MAC address dari radacct MixRadius ke legacy_mac_customer_map';

    public function handle(): int
    {
        $path = $this->resolvePath($this->argument('path'));

        if ($path === null) {
            $this->error("File tidak ditemukan: {$this->argument('path')}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $data);
            $rows[] = [
                'mac_address' => strtoupper(trim($row['mac_address'])),
                'legacy_username' => trim($row['legacy_username']),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        fclose($handle);

        LegacyMacCustomerMap::query()->insert($rows);

        $this->info(count($rows).' baris referensi MAC berhasil di-load.');

        return self::SUCCESS;
    }

    private function resolvePath(string $path): ?string
    {
        if (is_file($path)) {
            return $path;
        }

        $fallback = storage_path('app/'.ltrim($path, '/'));

        return is_file($fallback) ? $fallback : null;
    }
}
