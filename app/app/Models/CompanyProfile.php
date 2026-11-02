<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.19.1 — Profil Perusahaan per tenant (singleton, `tenant_id` unique).
 *
 * TIDAK pakai `BelongsToTenant` — CompanyProfileService me-resolve nilai
 * (termasuk untuk konsumen non-request seperti cetak invoice / persyaratan
 * WO nanti) dengan `tenant_id` eksplisit, bukan mengandalkan `Auth::check()`
 * — sama disiplin WorkOrderDispatchSettings / StaffService. Jadi query ke
 * model ini selalu menyebut `tenant_id` sendiri.
 */
class CompanyProfile extends Model
{
    protected $fillable = [
        'tenant_id',
        'legal_name',
        'brand_name',
        'address',
        'city',
        'postal_code',
        'npwp',
        'cs_whatsapp',
        'cs_phone',
        'email',
        'operating_hours',
        'bank_name',
        'bank_account_number',
        'bank_account_holder',
        'logo_path',
        'updated_by',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
