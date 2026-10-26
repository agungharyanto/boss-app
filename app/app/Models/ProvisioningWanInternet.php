<?php

namespace App\Models;

use App\Enums\WanMode;
use App\Enums\WifiRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Template "WAN Internet" (ZTP v0.23.8.3). `untested_on_hardware` di-sync
 * OTOMATIS dari `wan_mode` (pppoe=false, dhcp/static=true) di event saving —
 * jadi kolom tidak pernah drift dari mode-nya (keputusan #4).
 */
class ProvisioningWanInternet extends Model
{
    protected $fillable = [
        'provisioning_profile_id',
        'wan_mode',
        'untested_on_hardware',
        'vlan',
        'nat_enabled',
        'service_type',
        'wifi_role',
        'pppoe_username_strategy',
        'pppoe_username_static',
        'pppoe_password_strategy',
        'pppoe_password_static',
        'static_ip',
        'static_gateway',
        'static_netmask',
        'static_dns',
    ];

    protected function casts(): array
    {
        return [
            'wan_mode' => WanMode::class,
            'wifi_role' => WifiRole::class,
            'untested_on_hardware' => 'boolean',
            'nat_enabled' => 'boolean',
            'vlan' => 'integer',
            'pppoe_password_static' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            $mode = $model->wan_mode instanceof WanMode
                ? $model->wan_mode
                : WanMode::tryFrom((string) $model->wan_mode);
            if ($mode !== null) {
                $model->untested_on_hardware = $mode->untestedOnHardware();
            }
        });
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ProvisioningProfile::class, 'provisioning_profile_id');
    }
}
