<?php

namespace App\Models;

use Database\Factories\CpeDeviceModelCapabilityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-vendor/model SSID slot capability (2026-08-19) — platform-wide, not
 * tenant-scoped, same posture as CpeParameterMap. Resolved by
 * App\Services\Network\CpeParameterResolverService::resolveWlanConfigurations()
 * to decide how many SSID rows to render on the CPE detail page, including
 * empty/"Nonaktif" placeholders for slots this model has but the device
 * hasn't populated data for yet.
 */
class CpeDeviceModelCapability extends Model
{
    /** @use HasFactory<CpeDeviceModelCapabilityFactory> */
    use HasFactory;

    protected $fillable = [
        'oui',
        'product_class',
        'max_ssid_slots',
        'supports_5g',
        'verified_at',
        'verified_against_device_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'max_ssid_slots' => 'integer',
            'supports_5g' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
