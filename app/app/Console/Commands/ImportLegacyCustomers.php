<?php

namespace App\Console\Commands;

use App\Enums\CustomerStatus;
use App\Enums\RegistrationChannel;
use App\Enums\RegistrationStatus;
use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Full 561-row MixRadius customer import — deliberately customer-only, no
 * CPE binding at all (unlike App\Console\Commands\ImportLegacyCpeBindings,
 * which the earlier 28-row batch used). Most of these 561 customers don't
 * have a device visible in GenieACS yet at all, so binding is handled
 * separately and continuously by
 * App\Services\Network\LegacyDeviceMatcherService instead of a one-shot step
 * here. Per-row best-effort, same DB-transaction-per-row + catch-and-log
 * posture as every other legacy import command in this codebase. Safe to
 * re-run — an already-imported phone number is matched and enriched, never
 * duplicated.
 */
class ImportLegacyCustomers extends Command
{
    protected $signature = 'customers:import-legacy {path : Path to all_customers_import.csv}';

    protected $description = 'Import seluruh pelanggan valid dari export MixRadius (tanpa binding CPE)';

    private const TENANT_NAME = 'ISP Demo';

    public function handle(): int
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

        $created = 0;
        $updated = 0;
        $failures = [];

        foreach ($rows as $lineNumber => $row) {
            try {
                DB::transaction(function () use ($row, $tenant, &$created, &$updated) {
                    $phone = $this->normalizePhone($row['phone']);
                    $nik = trim((string) $row['nik']) !== '' ? trim($row['nik']) : null;
                    $legacyMemberId = trim((string) $row['legacy_member_id']) !== '' ? trim($row['legacy_member_id']) : null;
                    $legacyUsername = trim((string) $row['legacy_username']) !== '' ? trim($row['legacy_username']) : null;

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

                        if (blank($customer->legacy_username) && $legacyUsername !== null) {
                            $customer->legacy_username = $legacyUsername;
                            $dirty = true;
                        }

                        if (blank($customer->nik) && $nik !== null && ! Customer::nikAlreadyExists($nik, $tenant->id)) {
                            $customer->nik = $nik;
                            $dirty = true;
                        }

                        if ($dirty) {
                            $customer->save();
                        }

                        $updated++;

                        return;
                    }

                    Customer::create([
                        'tenant_id' => $tenant->id,
                        'reseller_id' => null,
                        'name' => trim($row['fullname']),
                        'address' => trim((string) $row['address']),
                        'phone_number' => $phone,
                        'nik' => $nik,
                        'status' => CustomerStatus::Aktif,
                        'registration_status' => RegistrationStatus::Active,
                        'registration_channel' => RegistrationChannel::LegacyImport,
                        'legacy_mixradius_member_id' => $legacyMemberId,
                        'legacy_username' => $legacyUsername,
                    ]);

                    $created++;
                });
            } catch (Throwable $e) {
                $failures[] = [
                    'line' => $lineNumber,
                    'phone' => $row['phone'] ?? '?',
                    'error' => $e->getMessage(),
                ];

                Log::warning('customers:import-legacy: gagal impor 1 baris.', [
                    'line' => $lineNumber,
                    'phone' => $row['phone'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Customer baru dibuat: {$created}");
        $this->info("Customer existing diupdate: {$updated}");
        $this->info('Baris gagal: '.count($failures));

        if ($failures !== []) {
            $this->table(['Baris', 'Phone', 'Error'], $failures);
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
     * Same normalization as ImportLegacyCpeBindings::normalizePhone() — kept as
     * its own copy rather than a shared trait since this is a one-off import
     * command, not a piece of application-wide business logic.
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
