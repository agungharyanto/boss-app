<?php

namespace App\Services\Company;

use App\Models\InstallationTerms;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * v0.19.1 — accessor "versi aktif" + penulisan versi BARU (INSERT-ONLY).
 */
class InstallationTermsService
{
    /**
     * Versi aktif = `version` tertinggi per tenant, atau null kalau belum
     * pernah ada (sebelum seed v1).
     */
    public function active(int $tenantId): ?InstallationTerms
    {
        return InstallationTerms::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @return Collection<int, InstallationTerms>
     */
    public function history(int $tenantId)
    {
        return InstallationTerms::query()
            ->where('tenant_id', $tenantId)
            ->with('createdBy')
            ->orderByDesc('version')
            ->get();
    }

    /**
     * Simpan body sebagai versi BARU (version tertinggi + 1). "Pakai ulang
     * versi lama" juga lewat sini — ia tetap angka versi baru, bukan menimpa.
     * Dibungkus transaksi + lockForUpdate supaya 2 simpan bersamaan tidak
     * menghasilkan angka versi kembar (ditolak unique index juga, ini
     * mencegahnya lebih dulu).
     */
    public function saveNewVersion(int $tenantId, string $body, ?int $createdBy): InstallationTerms
    {
        return DB::transaction(function () use ($tenantId, $body, $createdBy) {
            $maxVersion = (int) InstallationTerms::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->max('version');

            return InstallationTerms::query()->create([
                'tenant_id' => $tenantId,
                'version' => $maxVersion + 1,
                'body' => $body,
                'created_by' => $createdBy,
                'created_at' => now(),
            ]);
        });
    }
}
