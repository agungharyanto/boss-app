<?php

namespace App\Models;

use App\Enums\CustomerOptOutAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * v0.28.1 (T1) — jejak APPEND-ONLY opt-out/opt-in notifikasi otomatis
 * pelanggan. Lebih ketat dari `CompanyBankAccountLog`: model MENOLAK update &
 * delete (append-only ditegakkan di level model, bukan hanya service-layer).
 * Hanya `created_at` (tak ada `updated_at`). Tidak pakai BelongsToTenant —
 * `tenant_id` diisi eksplisit oleh service.
 */
class CustomerNotificationOptOutLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'action',
        'reason',
        'before',
        'after',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'action' => CustomerOptOutAction::class,
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Append-only: baris yang sudah ada tidak boleh diubah/dihapus lewat
        // Eloquent. (Hapus kaskade DB saat pelanggan dihapus tetap boleh —
        // itu bukan jalur Eloquent, jadi tidak terbentur guard ini.)
        static::updating(function (): void {
            throw new RuntimeException('customer_notification_opt_out_logs bersifat APPEND-ONLY — baris tidak boleh diubah.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('customer_notification_opt_out_logs bersifat APPEND-ONLY — baris tidak boleh dihapus.');
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
