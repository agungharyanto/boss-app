<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.28.2 (T2) — pemutus kirim otomatis per (tenant, session_key). Tanpa
 * BelongsToTenant (dipakai dari command/job/webhook tanpa Auth; tenant_id
 * eksplisit).
 */
class CustomerAutoSendBreaker extends Model
{
    protected $fillable = [
        'tenant_id',
        'session_key',
        'is_open',
        'consecutive_failures',
        'open_reason',
        'opened_at',
        'resumed_at',
        'resumed_by',
    ];

    protected function casts(): array
    {
        return [
            'is_open' => 'boolean',
            'consecutive_failures' => 'integer',
            'opened_at' => 'datetime',
            'resumed_at' => 'datetime',
        ];
    }

    public function resumedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resumed_by');
    }
}
