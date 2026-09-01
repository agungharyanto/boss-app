<?php

namespace App\Models;

use App\Enums\HotspotDurationUnit;
use App\Enums\MikrotikSyncStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PppPackageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * v0.14.5 — Profil PPP, a sellable monthly subscription package catalog
 * entry (equivalent to Profil Hotspot, v0.14.4, but for PPPoE customers).
 * See the migration's own docblock for the full "why no mikrotik_profile_name,
 * why no profile_type/limit_type/quota_*" reasoning.
 */
class PppPackage extends Model
{
    /** @use HasFactory<PppPackageFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'network_profile_group_id',
        'bandwidth_profile_id',
        'name',
        'visible_to_reseller',
        'cost_price',
        'sell_price',
        'promo_price',
        'tax_percent',
        'active_duration_value',
        'active_duration_unit',
        'shared_users',
        'priority',
        'login_days',
        'login_start_time',
        'login_end_time',
        'is_active',
        // mikrotik_sync_* — never part of a FormRequest's validated()
        // output, only written by markSync*() below, called from the push
        // Job. Same convention as HotspotPackage/CustomerIpPool/
        // NetworkProfileGroup.
        'mikrotik_sync_status',
        'mikrotik_synced_at',
        'mikrotik_sync_error',
    ];

    protected function casts(): array
    {
        return [
            'visible_to_reseller' => 'boolean',
            'cost_price' => 'decimal:2',
            'sell_price' => 'decimal:2',
            'promo_price' => 'decimal:2',
            'tax_percent' => 'decimal:2',
            'active_duration_unit' => HotspotDurationUnit::class,
            // Revisi Prioritas Dropdown — dulu string bebas ('Default'),
            // sekarang integer 1-8 (RouterOS Queue Priority) — lihat
            // App\Support\RouterOsQueuePriority untuk verifikasi range/
            // default dan mekanisme push-nya.
            'priority' => 'integer',
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
     * v0.9.3 — konfigurasi rate komisi untuk paket ini (satu baris per
     * paket, di-enforce oleh unique parsial di commission_rates). HasOne,
     * bukan HasMany. Null selama admin belum mengatur rate lewat
     * /commission-rates.
     */
    public function commissionRate(): HasOne
    {
        return $this->hasOne(CommissionRate::class);
    }

    /**
     * Stable per-row identifier for this Profil PPP's OWN `/ppp profile`
     * object — `/ppp profile` genuinely supports `comment` (confirmed via
     * a live add/set round trip, same as NetworkProfileGroup's own PPP
     * push), so — unlike HotspotPackage's mikrotikLookupName()/
     * mikrotik_profile_name workaround — a rename just works: the SAME
     * comment always resolves the SAME router object regardless of what
     * ->name currently is.
     */
    public function mikrotikComment(): string
    {
        return "BOSS App - PPP Package #{$this->id}";
    }

    /**
     * RouterOS session-timeout string for this package's Masa Aktif —
     * ALWAYS computed (unlike HotspotPackage::routerOsSessionTimeout(),
     * which returns null for Unlimited/QuotaBase) since active_duration_value/
     * unit are required, non-nullable fields for every Profil PPP — a plain
     * monthly subscription always has a real duration, there is no
     * "Unlimited" concept here.
     */
    public function routerOsSessionTimeout(): string
    {
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
     * The real collision risk this sub-version is built around (see the
     * migration's own docblock): a Profil PPP's own `/ppp profile` push
     * shares the SAME RouterOS `/ppp profile` name namespace, scoped
     * per-NAS, as every Grup Profil's own bare `/ppp profile` AND every
     * other Profil PPP under a DIFFERENT Grup Profil on that same NAS.
     * Checks BOTH sources — `network_profile_groups.name` and
     * `ppp_packages.name` — scoped to $nasId, excluding $ignorePackageId
     * (the row being edited, so a no-op rename of itself isn't flagged as
     * a collision with itself). Pure query logic, no HTTP/Livewire
     * concerns — called identically from Store/UpdatePppPackageRequest's
     * own withValidator() and PppPackageIndex's own mirrored check, same
     * "shared validation logic lives on the model, callers just invoke it"
     * pattern as CustomerIpPool::overlapsRange().
     */
    public static function collidesWithExistingName(int $nasId, string $name, ?int $ignorePackageId = null): bool
    {
        $groupCollision = NetworkProfileGroup::where('nas_id', $nasId)->where('name', $name)->exists();

        if ($groupCollision) {
            return true;
        }

        return self::whereHas('networkProfileGroup', fn ($query) => $query->where('nas_id', $nasId))
            ->where('name', $name)
            ->when($ignorePackageId, fn ($query, $id) => $query->whereKeyNot($id))
            ->exists();
    }
}
