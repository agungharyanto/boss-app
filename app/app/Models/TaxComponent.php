<?php

namespace App\Models;

use App\Enums\TaxComponentType;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TaxComponentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxComponent extends Model
{
    /** @use HasFactory<TaxComponentFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'type',
        'rate',
        'is_active',
        'effective_from',
        'effective_to',
        'description',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => TaxComponentType::class,
            'rate' => 'decimal:4',
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function policies(): HasMany
    {
        return $this->hasMany(ResellerTaxPolicy::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(ResellerTaxLedger::class);
    }

    public function remittanceSummaries(): HasMany
    {
        return $this->hasMany(KomdigiRemittanceSummary::class);
    }
}
