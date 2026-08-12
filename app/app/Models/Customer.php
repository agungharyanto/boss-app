<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Enums\RegistrationChannel;
use App\Enums\RegistrationStatus;
use App\Models\Concerns\BelongsToResellerScope;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToResellerScope, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'name',
        'address',
        'phone_number',
        'status',
        'referred_by_agent_id',
        'registration_status',
        'registration_channel',
        'legacy_mixradius_member_id',
        'legacy_username',
        'nik',
        'latitude',
        'longitude',
        'package',
    ];

    protected function casts(): array
    {
        return [
            'status' => CustomerStatus::class,
            'registration_status' => RegistrationStatus::class,
            'registration_channel' => RegistrationChannel::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'nik' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        // nik_hash is deliberately not fillable — it must only ever be
        // derived from nik, never set independently (that would let it
        // drift out of sync, or be used to smuggle a lookup value that
        // doesn't match the real encrypted nik). Recomputed on every save,
        // not just when nik changes, so it self-heals if a caller ever
        // bypasses this and sets nik_hash directly.
        static::saving(function (Customer $customer) {
            $customer->nik_hash = $customer->nik ? self::hashNik($customer->nik) : null;
        });
    }

    /**
     * Deterministic HMAC of a plaintext NIK — the only thing that can
     * actually be compared/queried for a unique-per-tenant NIK now that
     * `nik` itself is a randomized `encrypted` cast. Centralized here (not
     * inlined at each call site) since NIK_HMAC_KEY is security-sensitive —
     * every caller that needs to check/derive a nik_hash must go through
     * this one function.
     */
    public static function hashNik(string $nik): string
    {
        return hash_hmac('sha256', $nik, config('app.nik_hmac_key'));
    }

    /**
     * Whether a customer with this plaintext NIK already exists for the
     * given tenant — the "unique per tenant" check every NIK entry point
     * (API registration, Livewire registration, ...) must use instead of a
     * direct `nik` comparison, which is meaningless against an `encrypted`
     * column.
     */
    public static function nikAlreadyExists(string $nik, int $tenantId): bool
    {
        return self::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('nik_hash', self::hashNik($nik))
            ->exists();
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    public function authorizedContact(): HasOne
    {
        return $this->hasOne(CustomerContact::class)->where('is_authorized_contact', true);
    }

    public function timelineEntries(): HasMany
    {
        return $this->hasMany(CustomerTimelineEntry::class)->latest('created_at');
    }

    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'referred_by_agent_id');
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function commissionLedgerEntries(): HasMany
    {
        return $this->hasMany(CommissionLedger::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }
}
