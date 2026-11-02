<?php

namespace Database\Seeders;

use App\Models\InstallationTerms;
use App\Models\Tenant;
use App\Services\Company\InstallationTermsService;
use Illuminate\Database\Seeder;

/**
 * v0.19.1 — seed Syarat & Ketentuan Pemasangan VERSI 1 (DRAFT) per tenant.
 * Idempoten (firstOrCreate pada tenant_id+version=1) — aman di-run ulang,
 * tidak pernah menimpa versi yang sudah diedit admin lewat editor.
 *
 * Teks ini DRAFT — Agung mengubahnya lewat editor "Syarat & Ketentuan
 * Pemasangan" (tiap simpan = versi baru). Hanya memakai variabel whitelist
 * TermsRenderer::WHITELIST. {tanggal_jatuh_tempo} SENGAJA TIDAK dipakai
 * (tidak ada sumber andal saat render) — pasal 4 memakai kalimat
 * "sesuai tanggal jatuh tempo pada tagihan".
 */
class InstallationTermsSeeder extends Seeder
{
    public function run(): void
    {
        // DRAFT v1 — body tunggal dari InstallationTermsService (dipakai juga
        // oleh activeOrSeedDefault() di jalur tutup WO).
        $body = InstallationTermsService::DEFAULT_V1_BODY;

        Tenant::all()->each(function (Tenant $tenant) use ($body) {
            InstallationTerms::query()->firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'version' => 1,
                ],
                [
                    'body' => $body,
                    'created_by' => null,
                    'created_at' => now(),
                ]
            );
        });
    }
}
