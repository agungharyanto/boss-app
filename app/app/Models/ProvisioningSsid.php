<?php

namespace App\Models;

use App\Enums\SsidBand;
use App\Enums\SsidSecurity;
use App\Enums\WifiRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Template SSID per profil ZTP (v0.23.8.4). Invariant di-enforce di event
 * saving (bukan cuma UI): open → password DIPAKSA null; wpa2 → password WAJIB
 * (lempar kalau kosong).
 */
class ProvisioningSsid extends Model
{
    protected $fillable = [
        'provisioning_profile_id',
        'wifi_role',
        'band',
        'ssid_name',
        'security_mode',
        'password',
    ];

    protected function casts(): array
    {
        return [
            'wifi_role' => WifiRole::class,
            'band' => SsidBand::class,
            'security_mode' => SsidSecurity::class,
            'password' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            $mode = $model->security_mode instanceof SsidSecurity
                ? $model->security_mode
                : SsidSecurity::tryFrom((string) $model->security_mode);

            if ($mode === SsidSecurity::Open) {
                $model->password = null; // open tak pernah simpan password
            } elseif ($mode === SsidSecurity::Wpa2 && ($model->password === null || $model->password === '')) {
                throw new InvalidArgumentException('SSID wpa2 wajib punya password.');
            }
        });
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ProvisioningProfile::class, 'provisioning_profile_id');
    }
}
