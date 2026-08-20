<?php

namespace App\Console\Commands;

use App\Enums\CustomerStatus;
use App\Enums\RegistrationChannel;
use App\Enums\RegistrationStatus;
use App\Models\Customer;
use App\Models\Tenant;
use App\Services\Network\CpeBindingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One-off import of the 28 matched MixRadius customer/CPE rows (see
 * storage/app/imports/mixradius-cpe-match.csv) — the real-data end-to-end
 * test of the NIK-encryption + CID-generator + CpeBindingService
 * infrastructure built in earlier sessions. Per-row best-effort: one row's
 * failure (bad data, GenieACS unreachable, a constraint violation) is
 * caught and logged, never stops the remaining rows. Safe to re-run — an
 * already-imported phone number is matched and updated, not duplicated, and
 * CpeBindingService::bindFromLegacyImport() itself is an updateOrCreate.
 */
class ImportLegacyCpeBindings extends Command
{
    protected $signature = 'cpe:import-legacy-bindings {path : Path to the mixradius-cpe-match.csv file}';

    protected $description = 'Import 28 pelanggan+binding CPE hasil matching legacy MixRadius (storage/app/imports/mixradius-cpe-match.csv)';

    private const TENANT_NAME = 'ISP Demo';

    public function handle(CpeBindingService $cpeBindingService): int
    {
        $path = $this->resolvePath($this->argument('path'));

        if ($path === null) {
            $this->error("File tidak ditemukan: {$this->argument('path')}");

            return self::FAILURE;
        }

        $tenant = Tenant::where('name', self::TENANT_NAME)->first();

        if ($tenant === null) {
            $this->error('Tenant "'.self::TENANT_NAME.'" tidak ditemukan.');

            return self::FAILURE;
        }

        $rows = $this->readCsv($path);

        $customersCreated = 0;
        $customersUpdated = 0;
        $cpeBound = 0;
        $failures = [];

        foreach ($rows as $lineNumber => $row) {
            try {
                DB::transaction(function () use ($row, $tenant, $cpeBindingService, &$customersCreated, &$customersUpdated, &$cpeBound) {
                    $phone = $this->normalizePhone($row['phone']);
                    $nik = trim((string) $row['nik']) !== '' ? trim($row['nik']) : null;
                    $legacyMemberId = trim((string) $row['legacy_member_id']) !== '' ? trim($row['legacy_member_id']) : null;

                    $customer = Customer::withoutGlobalScopes()
                        ->where('tenant_id', $tenant->id)
                        ->where('phone_number', $phone)
                        ->first();

                    if ($customer !== null) {
                        $dirty = false;

                        if (blank($customer->legacy_mixradius_member_id) && $legacyMemberId !== null) {
                            $customer->legacy_mixradius_member_id = $legacyMemberId;
                            $dirty = true;
                        }

                        if (blank($customer->nik) && $nik !== null && ! Customer::nikAlreadyExists($nik, $tenant->id)) {
                            $customer->nik = $nik;
                            $dirty = true;
                        }

                        if ($dirty) {
                            $customer->save();
                        }

                        $customersUpdated++;
                    } else {
                        $customer = Customer::create([
                            'tenant_id' => $tenant->id,
                            'reseller_id' => null,
                            'name' => trim($row['fullname']),
                            'address' => trim($row['address']),
                            'phone_number' => $phone,
                            'nik' => $nik,
                            'status' => CustomerStatus::Aktif,
                            'registration_status' => RegistrationStatus::Active,
                            'registration_channel' => RegistrationChannel::LegacyImport,
                            'legacy_mixradius_member_id' => $legacyMemberId,
                        ]);

                        $customersCreated++;
                    }

                    $cpeBindingService->bindFromLegacyImport(
                        $customer,
                        trim($row['serial_number']),
                        trim((string) $row['match_confidence']) !== '' ? trim($row['match_confidence']) : null,
                    );

                    $cpeBound++;
                });
            } catch (Throwable $e) {
                $failures[] = [
                    'line' => $lineNumber,
                    'serial_number' => $row['serial_number'] ?? '?',
                    'error' => $e->getMessage(),
                ];

                Log::warning('cpe:import-legacy-bindings: gagal impor 1 baris.', [
                    'line' => $lineNumber,
                    'serial_number' => $row['serial_number'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Customer baru dibuat: {$customersCreated}");
        $this->info("Customer existing diupdate: {$customersUpdated}");
        $this->info("CPE berhasil di-bind: {$cpeBound}");
        $this->info('CPE gagal di-bind: '.count($failures));

        if ($failures !== []) {
            $this->table(['Baris', 'Serial Number', 'Error'], $failures);
        }

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

    /**
     * @return array<int, array<string, string>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        $rows = [];
        $lineNumber = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $lineNumber++;
            $rows[$lineNumber] = array_combine($header, $data);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Strips everything but digits and normalizes to the 08xx form this
     * codebase's phone numbers are stored in — a leading 62 (country code
     * with no +) becomes a leading 0; a bare 8xx (no leading 0 at all) gets
     * one prepended. Anything already 08xx passes through unchanged.
     */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (str_starts_with($digits, '62')) {
            return '0'.substr($digits, 2);
        }

        if (str_starts_with($digits, '8')) {
            return '0'.$digits;
        }

        return $digits;
    }
}
