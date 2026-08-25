<?php

namespace App\Models;

use App\Enums\CommissionStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CommissionLedgerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionLedger extends Model
{
    /** @use HasFactory<CommissionLedgerFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'commission_ledger';

    protected $fillable = [
        'tenant_id',
        'referrer_id',
        'customer_id',
        'amount',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => CommissionStatus::class,
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Referrer::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
