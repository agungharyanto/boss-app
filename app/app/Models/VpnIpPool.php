<?php

namespace App\Models;

use App\Enums\VpnIpPoolStatus;
use Database\Factories\VpnIpPoolFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VpnIpPool extends Model
{
    /** @use HasFactory<VpnIpPoolFactory> */
    use HasFactory;

    protected $table = 'vpn_ip_pool';

    protected $fillable = [
        'vpn_server_id',
        'ip_address',
        'status',
        'vpn_account_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => VpnIpPoolStatus::class,
        ];
    }

    public function vpnServer(): BelongsTo
    {
        return $this->belongsTo(VpnServer::class);
    }

    public function vpnAccount(): BelongsTo
    {
        return $this->belongsTo(VpnAccount::class);
    }
}
