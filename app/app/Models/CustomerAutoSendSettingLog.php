<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * v0.28.5 — jejak APPEND-ONLY perubahan pengaturan kirim otomatis pelanggan
 * (satu baris per field yang berubah). Pola sama CustomerNotificationOptOutLog:
 * model MENOLAK update & delete (append-only ditegakkan di level model). Hanya
 * `created_at`. Tanpa BelongsToTenant — `tenant_id` diisi eksplisit oleh
 * service (dipanggil dari komponen Livewire dengan tenant user aktif).
 */
class CustomerAutoSendSettingLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'field',
        'before',
        'after',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('customer_auto_send_setting_logs bersifat APPEND-ONLY — baris tidak boleh diubah.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('customer_auto_send_setting_logs bersifat APPEND-ONLY — baris tidak boleh dihapus.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
