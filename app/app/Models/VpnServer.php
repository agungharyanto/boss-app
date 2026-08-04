<?php

namespace App\Models;

use App\Enums\VpnServerStatus;
use App\Support\CidrRange;
use Database\Factories\VpnServerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VpnServer extends Model
{
    /** @use HasFactory<VpnServerFactory> */
    use HasFactory;

    protected $fillable = [
        'hostname',
        'public_ip',
        'subnet_cidr',
        'protocol_support',
        'max_clients',
        'current_clients',
        'status',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'protocol_support' => 'array',
            'status' => VpnServerStatus::class,
            'is_active' => 'boolean',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(VpnAccount::class);
    }

    public function ipPool(): HasMany
    {
        return $this->hasMany(VpnIpPool::class);
    }

    /**
     * Expands subnet_cidr into individual vpn_ip_pool rows (status
     * available) — same shape as Odp::provisionPorts(): a model method
     * called explicitly by the controller right after creation, NOT a
     * `created` event (avoids colliding with VpnIpPoolFactory-created rows
     * in tests, same reasoning as v0.5.0).
     */
    public function provisionIpPool(): void
    {
        collect(CidrRange::usableHostAddresses($this->subnet_cidr))->each(
            fn (string $ip) => $this->ipPool()->create(['ip_address' => $ip])
        );
    }
}
