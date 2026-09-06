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
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'paid_at',
        'paid_by',
        'payment_proof_path',
        'reversal_of_id',
        'reviewed_by',
        'reviewed_at',
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
            'paid_at' => 'datetime',
            'reviewed_at' => 'datetime',
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

    /**
     * v0.9.11 — admin yang menandai baris ini "dibayar" (Paid). NULL
     * selama status belum Paid.
     */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /**
     * v0.9.0 — admin yang meng-Approve/Reject baris komisi bulanan ini,
     * ATAU yang membuat baris Clawback ini. NULL kalau belum pernah
     * di-review / bukan baris reversal.
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * v0.9.0 — baris komisi asli yang di-reverse oleh baris Clawback ini
     * (NULL untuk baris non-reversal).
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * v0.9.0 — baris Clawback yang me-reverse baris ini (biasanya 0 atau 1;
     * `wasClawedBack()` menjaga tidak pernah lebih dari 1).
     *
     * @return HasMany<CommissionLedger, $this>
     */
    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of_id');
    }

    /**
     * v0.9.0 — baris ini sudah pernah di-clawback (ada baris reversal yang
     * menunjuk ke sini). Pakai relasi `reversals` yang sudah di-eager-load
     * kalau tersedia (hindari N+1 di daftar Fee Komisi).
     */
    public function wasClawedBack(): bool
    {
        return $this->relationLoaded('reversals')
            ? $this->reversals->isNotEmpty()
            : $this->reversals()->exists();
    }
}
