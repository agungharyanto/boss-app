<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.13.5.4 — jejak APPEND-ONLY perubahan rekening. before/after nomor sudah
 * ter-mask. Tidak pakai BelongsToTenant (tenant_id eksplisit).
 */
class CompanyBankAccountLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'bank_account_id',
        'action',
        'before',
        'after',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
