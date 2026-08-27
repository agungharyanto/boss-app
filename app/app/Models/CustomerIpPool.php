<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CustomerIpPoolFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * v0.14.2 — IP range allocated to a NAS's own end-customer devices
 * (hotspot/PPP), used starting v0.14.3 (Grup Profil) as a selectable
 * "Modul IP Pool". Deliberately NOT the same concept as VpnIpPool (v0.8.1,
 * the VPN tunnel address pool between a NAS and BOSS App itself) — see
 * CLAUDE.md's "Cluster Profil Paket" governance note.
 */
class CustomerIpPool extends Model
{
    /** @use HasFactory<CustomerIpPoolFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'nas_id',
        'name',
        'network_address',
        'gateway_ip',
        'range_start',
        'range_end',
        'dns_primary',
        'dns_secondary',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function nas(): BelongsTo
    {
        return $this->belongsTo(Nas::class);
    }

    /**
     * True if [range_start, range_end] (this instance, in-memory — not
     * necessarily persisted yet) numerically overlaps $other's range.
     * Pure comparison, no query — CustomerIpPoolService is what actually
     * queries sibling pools on the same NAS and calls this per candidate.
     */
    public function overlapsRange(self $other): bool
    {
        $thisStart = ip2long($this->range_start);
        $thisEnd = ip2long($this->range_end);
        $otherStart = ip2long($other->range_start);
        $otherEnd = ip2long($other->range_end);

        return $thisStart <= $otherEnd && $otherStart <= $thisEnd;
    }
}
