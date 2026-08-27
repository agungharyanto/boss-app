<?php

namespace App\Models;

use App\Enums\NasStatus;
use App\Models\Concerns\BelongsToResellerScope;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\NasFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Nas extends Model
{
    /** @use HasFactory<NasFactory> */
    use BelongsToResellerScope, BelongsToTenant, HasFactory;

    protected $table = 'nas';

    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'name',
        'description',
        'mikrotik_ip',
        'tr069_management_subnet',
        'api_port',
        'api_username',
        'api_password',
        'radius_secret',
        'auth_port',
        'acct_port',
        'coa_port',
        'status',
        'last_ping_at',
        'timezone',
    ];

    protected $hidden = [
        'api_password',
        'radius_secret',
    ];

    protected function casts(): array
    {
        return [
            'api_password' => 'encrypted',
            'radius_secret' => 'encrypted',
            'status' => NasStatus::class,
            'last_ping_at' => 'datetime',
        ];
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function vpnAccounts(): HasMany
    {
        return $this->hasMany(VpnAccount::class);
    }

    /**
     * v0.14.x incident fix — used by VpnProvisioningService::
     * issueWireGuardCredentials() to only widen AllowedIPs with the OLT
     * management subnet for a NAS that actually has at least one OLT
     * registered, instead of unconditionally widening every WireGuard
     * NAS's AllowedIPs the same way (the v0.8.1 design that caused a real
     * ~2-day LibreNMS OLT monitoring outage — see CLAUDE.md's own
     * "OLT AllowedIPs Conflict" section for the full incident writeup).
     */
    public function oltDevices(): HasMany
    {
        return $this->hasMany(OltDevice::class);
    }

    /**
     * v0.14.2 — a NAS's own end-customer IP pools (hotspot/PPP), NOT the
     * VPN tunnel address pool (that's VpnIpPool, unrelated to Nas at all —
     * see CustomerIpPool's own docblock for the distinction).
     */
    public function customerIpPools(): HasMany
    {
        return $this->hasMany(CustomerIpPool::class);
    }

    /**
     * v0.14.3 — a NAS's own Grup Profil templates (Hotspot/PPP).
     */
    public function networkProfileGroups(): HasMany
    {
        return $this->hasMany(NetworkProfileGroup::class);
    }
}
