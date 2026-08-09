<?php

namespace App\Models;

use App\Enums\CpeParameterConversionFormula;
use Database\Factories\CpeParameterMapFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-vendor/model TR-069 parameter mapping (v0.7.2) — platform-wide, not
 * tenant-scoped (a hardware fact about the model, same posture as
 * PaymentGatewayChannel). Resolved by App\Services\Network\
 * CpeParameterResolverService, matched against a real GenieACS device's
 * `_deviceId._OUI`/`_deviceId._ProductClass`.
 */
class CpeParameterMap extends Model
{
    /** @use HasFactory<CpeParameterMapFactory> */
    use HasFactory;

    protected $fillable = [
        'oui',
        'product_class',
        'parameter_key',
        'parameter_path',
        'value_type',
        'conversion_formula',
        'conversion_params',
        'verified_at',
        'verified_against_device_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'conversion_formula' => CpeParameterConversionFormula::class,
            'conversion_params' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
