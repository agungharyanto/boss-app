<?php

use App\Models\Tenant;
use App\Services\Company\CompanyBankAccountService;
use Illuminate\Database\Migrations\Migration;

/**
 * v0.13.5.4 — migrasi data: untuk tiap tenant yang company_profiles.bank_* nya
 * terisi DAN belum punya baris company_bank_accounts, buat 1 rekening default
 * aktif dari data lama. Idempoten (aman diulang; skip bila sudah ada rekening).
 * Kolom lama company_profiles.bank_* dibiarkan (deprecated).
 */
return new class extends Migration
{
    public function up(): void
    {
        $service = app(CompanyBankAccountService::class);

        Tenant::query()->pluck('id')->each(function (int $tenantId) use ($service) {
            $service->backfillFromProfile($tenantId);
        });
    }

    public function down(): void
    {
        // Tidak ada rollback data (baris dibiarkan; kolom lama masih sumbernya).
    }
};
