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
        ];
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
}
