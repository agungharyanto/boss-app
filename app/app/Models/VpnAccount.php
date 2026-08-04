<?php

namespace App\Models;

use App\Enums\VpnAccountStatus;
use Database\Factories\VpnAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VpnAccount extends Model
{
    /** @use HasFactory<VpnAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'nas_id',
        'vpn_server_id',
        'protocol',
        'username',
        'password',
        'internal_ip',
        'cert_serial',
        'status',
        'issued_at',
        'revoked_at',
        'connected_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'status' => VpnAccountStatus::class,
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
            'connected_at' => 'datetime',
        ];
    }

    public function nas(): BelongsTo
    {
        return $this->belongsTo(Nas::class);
    }

    public function vpnServer(): BelongsTo
    {
        return $this->belongsTo(VpnServer::class);
    }

    public function ipPoolEntry(): HasOne
    {
        return $this->hasOne(VpnIpPool::class);
    }
}
