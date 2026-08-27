<?php

namespace App\Models;

use App\Enums\HotspotDurationUnit;
use App\Enums\HotspotLimitType;
use App\Enums\HotspotProfileType;
use App\Enums\MikrotikSyncStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\HotspotPackageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * v0.14.4 — Profil Hotspot, a sellable voucher/token package catalog entry.
 * See the migration's own docblock for the full "why standalone, not wired
 * to reseller_package_pricing" reasoning and the mikrotik_profile_name
 * addition.
 */
class HotspotPackage extends Model
{
    /** @use HasFactory<HotspotPackageFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'network_profile_group_id',
        'bandwidth_profile_id',
        'name',
        'visible_to_reseller',
        'show_in_voucher_form',
        'cost_price',
        'sell_price',
        'promo_price',
        'tax_percent',
        'profile_type',
        'limit_type',
        'active_duration_value',
        'active_duration_unit',
        'shared_users',
        'priority',
        'login_days',
        'login_start_time',
        'login_end_time',
        'is_active',
        // mikrotik_sync_*/mikrotik_profile_name — never part of a
        // FormRequest's validated() output, only written by markSync*()
        // below, called from the push Job.
        'mikrotik_sync_status',
        'mikrotik_synced_at',
        'mikrotik_sync_error',
        'mikrotik_profile_name',
    ];

    protected function casts(): array
    {
        return [
            'visible_to_reseller' => 'boolean',
            'show_in_voucher_form' => 'boolean',
            'cost_price' => 'decimal:2',
            'sell_price' => 'decimal:2',
            'promo_price' => 'decimal:2',
            'tax_percent' => 'decimal:2',
            'profile_type' => HotspotProfileType::class,
            'limit_type' => HotspotLimitType::class,
            'active_duration_unit' => HotspotDurationUnit::class,
            'login_days' => 'array',
            'is_active' => 'boolean',
            'mikrotik_sync_status' => MikrotikSyncStatus::class,
            'mikrotik_synced_at' => 'datetime',
        ];
    }

    public function networkProfileGroup(): BelongsTo
    {
        return $this->belongsTo(NetworkProfileGroup::class);
    }

    public function bandwidthProfile(): BelongsTo
    {
        return $this->belongsTo(BandwidthProfile::class);
    }

    /**
     * The name to actually push to the router THIS sync — always the
     * package's own current name. See mikrotikLookupName() for the name to
     * search BY (which may be a stale, previously-synced name after a
     * rename).
     */
    public function mikrotikTargetName(): string
    {
        return $this->name;
    }

    /**
     * The name RouterOsGateway::syncHotspotUserProfile() should look up an
     * existing `/ip hotspot user profile` object BY — `/ip hotspot user
     * profile` has no `comment` field to key off (confirmed empirically,
     * see the migration's own docblock), so `mikrotik_profile_name` (the
     * name that was actually pushed last time) is the closest available
     * stable identifier. Falls back to the CURRENT name only when nothing
     * has ever been synced yet (a brand-new package, or one whose push has
     * never once succeeded) — in that case there is nothing meaningful to
     * look up by anyway, and the gateway's own create-if-missing logic
     * handles it.
     */
    public function mikrotikLookupName(): string
    {
        return $this->mikrotik_profile_name ?? $this->name;
    }

    /**
     * RouterOS session-timeout string for this package's Masa Aktif — only
     * meaningful when profile_type=Limited AND limit_type=TimeBase (see
     * HotspotLimitType's own docblock for why QuotaBase has nothing to push
     * here). Returns null otherwise, meaning "don't set session-timeout at
     * all" — RouterOsApiGateway::syncHotspotUserProfile() omits the field
     * entirely rather than push a 0/empty value.
     */
    public function routerOsSessionTimeout(): ?string
    {
        if ($this->profile_type !== HotspotProfileType::Limited) {
            return null;
        }

        if ($this->limit_type !== HotspotLimitType::TimeBase) {
            return null;
        }

        if ($this->active_duration_value === null || $this->active_duration_unit === null) {
            return null;
        }

        $unit = $this->active_duration_unit;

        return $unit->routerOsValue($this->active_duration_value).$unit->routerOsSuffix();
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
            'mikrotik_profile_name' => $this->name,
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
