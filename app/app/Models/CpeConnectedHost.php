<?php

namespace App\Models;

use App\Models\Concerns\BelongsToResellerScope;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CpeConnectedHostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (CpeDevice, MAC address) ever seen in that device's TR-069
 * `Hosts.Host` object — v0.7.6. Deliberately NOT one row per poll (would
 * grow unbounded) — App\Services\Network\CpeConnectedHostsService upserts
 * in place, keyed on mac_address, and never deletes a row once created
 * (is_active just flips to false when a MAC stops appearing).
 */
class CpeConnectedHost extends Model
{
    /** @use HasFactory<CpeConnectedHostFactory> */
    use BelongsToResellerScope, BelongsToTenant, HasFactory;

    protected $fillable = [
        'cpe_device_id',
        'tenant_id',
        'reseller_id',
        'mac_address',
        'hostname',
        'ip_address',
        'is_active',
        'first_seen_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function cpeDevice(): BelongsTo
    {
        return $this->belongsTo(CpeDevice::class);
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }
}
