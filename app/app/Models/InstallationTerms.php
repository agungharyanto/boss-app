<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.19.1 — satu baris = satu VERSI teks Syarat & Ketentuan Pemasangan,
 * INSERT-ONLY. Versi aktif = `version` tertinggi per tenant
 * (lihat InstallationTermsService::active()).
 *
 * Tanpa `updated_at` (baris tidak pernah di-update); `created_at` diisi
 * manual saat insert. TIDAK pakai `BelongsToTenant` — alasan sama dengan
 * CompanyProfile: dikonsumsi lintas konteks dengan `tenant_id` eksplisit.
 */
class InstallationTerms extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'version',
        'body',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
