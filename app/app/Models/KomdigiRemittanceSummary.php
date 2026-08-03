<?php

namespace App\Models;

use App\Enums\RemittanceStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\KomdigiRemittanceSummaryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KomdigiRemittanceSummary extends Model
{
    /** @use HasFactory<KomdigiRemittanceSummaryFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'komdigi_remittance_summary';

    protected $fillable = [
        'tenant_id',
        'period_start',
        'period_end',
        'reseller_id',
        'tax_component_id',
        'total_base_amount',
        'total_tax_amount',
        'total_customer_borne',
        'total_reseller_borne',
        'transaction_count',
        'status',
        'generated_at',
        'remitted_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'total_base_amount' => 'decimal:2',
            'total_tax_amount' => 'decimal:2',
            'total_customer_borne' => 'decimal:2',
            'total_reseller_borne' => 'decimal:2',
            'transaction_count' => 'integer',
            'status' => RemittanceStatus::class,
            'generated_at' => 'datetime',
            'remitted_at' => 'datetime',
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
}
