<?php

namespace App\Models;

use App\Enums\TaxBurden;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ResellerTaxPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerTaxPolicy extends Model
{
    /** @use HasFactory<ResellerTaxPolicyFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'tax_component_id',
        'burden',
        'split_ratio',
        'is_active',
        'effective_from',
        'effective_to',
        'set_by',
    ];

    protected function casts(): array
    {
        return [
            'burden' => TaxBurden::class,
            'split_ratio' => 'decimal:2',
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function taxComponent(): BelongsTo
    {
        return $this->belongsTo(TaxComponent::class);
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
