<?php

namespace App\Models;

use App\Enums\MikrotikSyncStatus;
use App\Enums\NetworkProfileGroupType;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\NetworkProfileGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * v0.14.3 — a NAS-scoped RADIUS/Mikrotik profile template (Hotspot or PPP),
 * referencing a CustomerIpPool (v0.14.2) from the SAME NAS. Used starting
 * v0.14.4/v0.14.5 (Profil Hotspot/Profil PPP) as a selectable reference —
 * this sub-version only builds the template itself, not customer-level
 * assignment.
 */
class NetworkProfileGroup extends Model
{
    /** @use HasFactory<NetworkProfileGroupFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'nas_id',
        'name',
        'type',
        'customer_ip_pool_id',
        'dns_primary',
        'dns_secondary',
        'parent_queue',
        'is_active',
        // mikrotik_sync_* — see CustomerIpPool's own identical note: never
        // part of a FormRequest's validated() output, only written by
        // markSync*() below, called from the push Job.
        'mikrotik_sync_status',
        'mikrotik_synced_at',
        'mikrotik_sync_error',
    ];

    protected function casts(): array
    {
        return [
            'type' => NetworkProfileGroupType::class,
            'is_active' => 'boolean',
            'mikrotik_sync_status' => MikrotikSyncStatus::class,
            'mikrotik_synced_at' => 'datetime',
        ];
    }

    public function nas(): BelongsTo
    {
        return $this->belongsTo(Nas::class);
    }

    public function customerIpPool(): BelongsTo
    {
        return $this->belongsTo(CustomerIpPool::class);
    }

    /**
     * Stable per-row identifier for the `/ppp profile` object on the
     * router — same "lookup by comment, not name" reasoning as
     * CustomerIpPool::mikrotikComment(). PPP type only; Hotspot type never
     * creates a distinct router object (see PushNetworkProfileGroupToMikrotikJob's
     * own docblock for why).
     */
    public function mikrotikComment(): string
    {
        return "BOSS App - Network Profile Group #{$this->id}";
    }

    /**
     * Stable FreeRADIUS radgroupreply/radgroupcheck GroupName for this row
     * — see NetworkProfileGroupService's own docblock for the full
     * reasoning behind writing to these tables (a genuinely new pattern in
     * this codebase, confirmed with Agung before implementing).
     */
    public function radiusGroupName(): string
    {
        return "boss-grup-profil-{$this->id}";
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
}
