<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tipe Modem — dikelola admin, diisi manual oleh teknisi saat instalasi
 * (`WorkOrderDevice::modem_type_id`), dipakai sebagai satu sumbu matrix
 * `WanConfigTemplate` (Paket × Tipe Modem). Lihat migration
 * `create_modem_types_table` untuk kenapa ini BUKAN hasil deteksi otomatis
 * dari `cpe_devices.manufacturer`/`model_name`.
 */
class ModemType extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function wanConfigTemplates(): HasMany
    {
        return $this->hasMany(WanConfigTemplate::class);
    }
}
