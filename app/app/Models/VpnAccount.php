<?php

namespace App\Models;

use App\Enums\VpnAccountStatus;
use App\Enums\VpnProtocol;
use Database\Factories\VpnAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VpnAccount extends Model
{
    /** @use HasFactory<VpnAccountFactory> */
    use HasFactory;

    /**
     * WireGuard private key — set ONLY by VpnProvisioningService right
     * after generating a fresh keypair, and ONLY for that one response.
     * Deliberately a genuine declared PHP property, NOT an Eloquent
     * attribute/DB column — bypasses Eloquent's magic __get/__set entirely,
     * so it can never accidentally get written to the database by a later
     * ->save()/->update() call, and never survives a ->fresh()/reload.
     */
    public ?string $wireguardPrivateKey = null;

    protected $fillable = [
        'nas_id',
        'vpn_server_id',
        'protocol',
        'username',
        'password',
        'internal_ip',
        'cert_serial',
        'public_key',
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
            'protocol' => VpnProtocol::class,
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
