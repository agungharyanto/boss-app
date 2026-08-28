<?php

namespace App\Models;

use App\Enums\MikrotikSyncStatus;
use App\Enums\NasStatus;
use App\Models\Concerns\BelongsToResellerScope;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\NasFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
        // Revisi Grup Profil — "Profile Pelanggan Expired" fallback per NAS.
        // expired_profile_mikrotik_* ARE fillable (same convention as
        // NetworkProfileGroup/CustomerIpPool's own mikrotik_sync_* fields)
        // — they're just never part of any FormRequest/Livewire form's
        // bound input, only ever written via update() inside
        // markExpiredProfileSync*() below, called from the push/remove Job.
        // A real bug caught here: an earlier version of this list omitted
        // them entirely, which silently no-op'd every markExpiredProfileSync*()
        // update() call (Eloquent mass-assignment protection drops any
        // non-fillable key with zero error) — caught by
        // ExpiredProfileMikrotikSyncTest, not by review.
        'expired_ip_pool_id',
        'expired_profile_mikrotik_sync_status',
        'expired_profile_mikrotik_synced_at',
        'expired_profile_mikrotik_sync_error',
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
            'expired_profile_mikrotik_sync_status' => MikrotikSyncStatus::class,
            'expired_profile_mikrotik_synced_at' => 'datetime',
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

    /**
     * Revisi Grup Profil — the IP Pool a NAS's own "Profile Pelanggan
     * Expired" fallback `/ppp profile` gets its `local-address` from (see
     * the migration's own docblock for the full Winbox-reference-pattern
     * reasoning). Deliberately NOT constrained to only pools belonging to
     * THIS same NAS at the Eloquent relation level — that's a real
     * business rule enforced in the FormRequest/Livewire validation layer
     * instead, same "relation stays simple, validation layer owns the
     * cross-entity rule" split already established throughout this
     * codebase (e.g. NetworkProfileGroup::customerIpPool()).
     */
    public function expiredIpPool(): BelongsTo
    {
        return $this->belongsTo(CustomerIpPool::class, 'expired_ip_pool_id');
    }

    /**
     * Stable per-row identifier for this NAS's own "Profile Pelanggan
     * Expired" `/ppp profile` object — same "lookup by comment" reasoning
     * as every other `/ppp profile` push in this codebase (confirmed
     * `/ppp profile` supports `comment`, unlike `/ip hotspot user
     * profile`).
     */
    public function expiredProfileMikrotikComment(): string
    {
        return "BOSS App - Expired Profile NAS #{$this->id}";
    }

    /**
     * RouterOS object name for this NAS's expired fallback profile — kept
     * distinct per NAS (a NAS's own id embedded) since `/ppp profile` names
     * are router-wide, not scoped to "which NAS pushed this".
     */
    public function expiredProfileMikrotikName(): string
    {
        return "expired-nas-{$this->id}";
    }

    public function markExpiredProfileSyncPending(): void
    {
        $this->update(['expired_profile_mikrotik_sync_status' => MikrotikSyncStatus::Pending, 'expired_profile_mikrotik_sync_error' => null]);
    }

    public function markExpiredProfileSynced(): void
    {
        $this->update([
            'expired_profile_mikrotik_sync_status' => MikrotikSyncStatus::Synced,
            'expired_profile_mikrotik_synced_at' => Carbon::now(),
            'expired_profile_mikrotik_sync_error' => null,
        ]);
    }

    public function markExpiredProfileSyncFailed(string $message): void
    {
        $this->update([
            'expired_profile_mikrotik_sync_status' => MikrotikSyncStatus::Failed,
            'expired_profile_mikrotik_sync_error' => $message,
        ]);
    }
}
