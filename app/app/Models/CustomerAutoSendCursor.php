<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * v0.28.4 (SEBAR-BATCH) — kursor jeda-batch per (tenant, session_key). Lihat
 * CustomerAutoSendBatchPlanner untuk aturan reservasi slot (reset / advance /
 * batas jendela). Tanpa BelongsToTenant (dipakai dari gerbang tanpa Auth;
 * tenant_id eksplisit, pola CustomerAutoSendBreaker).
 */
class CustomerAutoSendCursor extends Model
{
    protected $fillable = [
        'tenant_id',
        'session_key',
        'next_slot_at',
        'batch_count',
    ];

    protected function casts(): array
    {
        return [
            'next_slot_at' => 'datetime',
            'batch_count' => 'integer',
        ];
    }
}
