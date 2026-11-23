<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.28.2 (T2) — penundaan notifikasi customer_registered. SATU baris
 * 'pending' aktif per customer (partial unique). Selesai ditandai `status`
 * (sent/simulated/cancelled), baris tidak dihapus. Tanpa updated_at.
 */
class CustomerAutoSendDeferral extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_SIMULATED = 'simulated';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'status',
        'meta',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
