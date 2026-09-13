<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ModemTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tipe Modem — dikelola admin, diisi manual oleh teknisi saat instalasi
 * (`WorkOrderDevice::modem_type_id`), dipakai sebagai satu sumbu matrix
 * `WanConfigTemplate`. Lihat migration `create_modem_types_table` untuk
 * kenapa ini BUKAN hasil deteksi otomatis dari `cpe_devices.manufacturer`/
 * `model_name`.
 *
 * `manufacturer_match_patterns` (v0.12.5) — dasar auto-suggest
 * (App\Services\Network\WanConfigTemplateSuggestionService): comma-
 * separated kode OUI GenieACS (mis. "ZICG,CIOT"), diisi MANUAL admin
 * kapan pun tahu kode OUI suatu Tipe Modem — bukan hasil deteksi
 * otomatis juga, sama filosofi dengan Tipe Modem itu sendiri.
 */
class ModemType extends Model
{
    /** @use HasFactory<ModemTypeFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'manufacturer_match_patterns',
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

    /**
     * @return list<string> pattern ter-normalisasi (uppercase, trim) —
     *                      siap dibandingkan langsung terhadap `cpe_devices.manufacturer`
     *                      yang juga dinormalisasi sama (lihat
     *                      WanConfigTemplateSuggestionService::normalizeManufacturer()).
     */
    public function matchPatterns(): array
    {
        return collect(preg_split('/[\s,;]+/', (string) $this->manufacturer_match_patterns))
            ->map(fn ($p) => strtoupper(trim($p)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
