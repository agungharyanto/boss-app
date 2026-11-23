<?php

namespace App\Models;

use App\Enums\CustomerAutoSendResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.28.2 (T2) — baris keputusan gerbang (tabel customer_auto_send_decisions).
 * Operasional (bukan append-only); di-prune > 30 hari. Tanpa updated_at.
 */
class CustomerAutoSendDecisionLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'customer_auto_send_decisions';

    protected $fillable = [
        'tenant_id',
        'path',
        'customer_id',
        'invoice_id',
        'result',
        'simulated',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'result' => CustomerAutoSendResult::class,
            'simulated' => 'boolean',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
