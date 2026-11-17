<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.13.5.4 — rekening tujuan transfer manual per tenant. SENGAJA TIDAK pakai
 * BelongsToTenant: dibaca dari jalur render PDF / sweep tanpa Auth; semua query
 * memfilter tenant_id eksplisit (pola whatsapp_notification_groups).
 */
class CompanyBankAccount extends Model
{
    protected $fillable = [
        'tenant_id',
        'bank_name',
        'account_number',
        'account_holder',
        'sort_order',
        'is_default',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Nomor rekening ter-mask untuk log/audit (hanya 4 digit terakhir terlihat).
     */
    public static function maskNumber(?string $number): string
    {
        $number = (string) $number;
        if ($number === '') {
            return '';
        }

        $digits = preg_replace('/\s+/', '', $number);
        $len = strlen($digits);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - 4).substr($digits, -4);
    }

    public function maskedNumber(): string
    {
        return self::maskNumber($this->account_number);
    }
}
