<?php

namespace App\Models;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Enums\TitipDepositStatus;
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
        'gross_amount',
        'scheme',
        'payment_period',
        'status',
        'deposit_status',
        'deposited_at',
        'deposited_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'scheme' => CommissionScheme::class,
            'payment_period' => 'date',
            'status' => CommissionStatus::class,
            'deposit_status' => TitipDepositStatus::class,
            'deposited_at' => 'datetime',
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

    /**
     * Admin yang menandai baris Titip ini "sudah setor" (NULL selama masih
     * `belum_setor` / untuk skema non-titip).
     */
    public function depositedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deposited_by');
    }
}
