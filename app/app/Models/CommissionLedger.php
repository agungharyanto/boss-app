<?php

namespace App\Models;

use App\Enums\CommissionScheme;
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
        'invoice_id',
        'amount',
        'scheme',
        'payment_period',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'scheme' => CommissionScheme::class,
            'payment_period' => 'date',
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

    /**
     * v0.9.5 — invoice yang memicu baris komisi ini matang/lahir. NULL untuk
     * baris "template" dari registrasi yang belum pernah tersambung ke
     * invoice lunas.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
