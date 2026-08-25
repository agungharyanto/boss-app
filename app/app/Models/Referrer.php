<?php

namespace App\Models;

use App\Enums\ReferrerType;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ReferrerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Referrer extends Model
{
    /** @use HasFactory<ReferrerFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'referrers';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'name',
        'phone',
        'type',
        'commission_rate',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => ReferrerType::class,
            'commission_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Customer::class, 'referred_by_referrer_id');
    }

    public function commissionLedgerEntries(): HasMany
    {
        return $this->hasMany(CommissionLedger::class);
    }
}
