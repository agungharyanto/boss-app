<?php

namespace App\Models;

use App\Enums\CustomerIpPoolUsageType;
use App\Enums\MikrotikSyncStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CustomerIpPoolFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

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
        'usage_type',
        'network_address',
        'gateway_ip',
        'range_start',
        'range_end',
        'dns_primary',
        'dns_secondary',
        'is_active',
        // mikrotik_sync_* are deliberately still listed here (mass-
        // assignable) but are NEVER part of StoreCustomerIpPoolRequest/
        // UpdateCustomerIpPoolRequest's validated() output — only
        // PushCustomerIpPoolToMikrotikJob (via markSync*() below) ever
        // writes them, never a direct user request.
        'mikrotik_sync_status',
        'mikrotik_synced_at',
        'mikrotik_sync_error',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'usage_type' => CustomerIpPoolUsageType::class,
            'mikrotik_sync_status' => MikrotikSyncStatus::class,
            'mikrotik_synced_at' => 'datetime',
        ];
    }

    public function nas(): BelongsTo
    {
        return $this->belongsTo(Nas::class);
    }

    /**
     * The stable identifier PushCustomerIpPoolToMikrotikJob writes into
     * the RouterOS `/ip pool` object's own `comment` field — looked up by
     * THIS, never by `name`, so renaming a pool in BOSS App updates the
     * existing router object instead of creating an orphaned duplicate.
     * Same "BOSS App - <thing> <identifier>" comment convention already
     * established by MikrotikScriptGenerator elsewhere in this codebase.
     */
    public function mikrotikComment(): string
    {
        return "BOSS App - Customer IP Pool #{$this->id}";
    }

    public function markSyncPending(): void
    {
        $this->update(['mikrotik_sync_status' => MikrotikSyncStatus::Pending, 'mikrotik_sync_error' => null]);
    }

    public function markSynced(): void
    {
        $this->update([
            'mikrotik_sync_status' => MikrotikSyncStatus::Synced,
            'mikrotik_synced_at' => Carbon::now(),
            'mikrotik_sync_error' => null,
        ]);
    }

    public function markSyncFailed(string $message): void
    {
        $this->update([
            'mikrotik_sync_status' => MikrotikSyncStatus::Failed,
            'mikrotik_sync_error' => $message,
        ]);
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
