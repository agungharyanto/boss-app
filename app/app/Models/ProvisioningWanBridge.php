<?php

namespace App\Models;

use App\Enums\WifiRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Template "WAN Bridge" (ZTP v0.23.8.3) — bridge murni (tanpa PPPoE/NAT).
 */
class ProvisioningWanBridge extends Model
{
    protected $fillable = [
        'provisioning_profile_id',
        'vlan',
        'service_type',
        'wifi_role',
    ];

    protected function casts(): array
    {
        return [
            'wifi_role' => WifiRole::class,
            'vlan' => 'integer',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ProvisioningProfile::class, 'provisioning_profile_id');
    }
}
