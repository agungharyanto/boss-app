<?php

namespace App\Models;

use App\Enums\TaxBurden;
use App\Enums\TaxLedgerSource;
use App\Enums\TaxLedgerStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ResellerTaxLedgerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ResellerTaxLedger extends Model
{
    /** @use HasFactory<ResellerTaxLedgerFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'reseller_tax_ledger';

    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'tax_component_id',
        'reference_type',
        'reference_id',
        'base_amount',
        'rate_applied',
        'tax_amount',
        'burden_applied',
        'customer_borne_amount',
        'reseller_borne_amount',
        'transaction_date',
        'status',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'base_amount' => 'decimal:2',
            'rate_applied' => 'decimal:4',
            'tax_amount' => 'decimal:2',
            'burden_applied' => TaxBurden::class,
            'customer_borne_amount' => 'decimal:2',
            'reseller_borne_amount' => 'decimal:2',
            'transaction_date' => 'date',
            'status' => TaxLedgerStatus::class,
            'source' => TaxLedgerSource::class,
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

    /**
     * No FK constraint backs reference_type/reference_id (see migration) —
     * this stays a plain polymorphic relation to whatever model writes here
     * (App\Models\Invoice starting v0.3.4).
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
