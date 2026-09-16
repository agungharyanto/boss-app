<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.26.2 — PER TENANT (satu baris per tenant, `tenant_id` unique) —
 * dikonfirmasi lewat investigasi Langkah 0: BUKAN platform-level
 * singleton seperti `PaymentGatewaySettings`/`WhatsappGatewaySettings`
 * (keduanya cuma 1 baris untuk seluruh deployment ISP, tidak cocok untuk
 * kebutuhan ini). Nama sengaja BUKAN mirip `WhatsappGatewaySettings` —
 * domain/scope beda total (timing dispatch/reminder Work Order, bukan
 * rate-limit pengiriman WA).
 *
 * Model ini TIDAK pakai `BelongsToTenant` — dipanggil dari command yang
 * berjalan lintas-tenant tanpa Auth (`WorkOrderDispatchCommand`, lihat
 * itu), jadi query-nya sengaja eksplisit `tenant_id` sendiri, sama
 * disiplin `App\Services\StaffService`.
 */
class WorkOrderDispatchSettings extends Model
{
    protected $fillable = [
        'tenant_id',
        'dispatch_offset_minutes',
        'reminder_time',
        'command_interval_minutes',
        'wa_group_name',
        'wa_group_jid',
        'last_dispatch_run_at',
    ];

    protected function casts(): array
    {
        return [
            'last_dispatch_run_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Semua default dituliskan eksplisit di sini (bukan mengandalkan
     * kolom DB default saja) — `firstOrCreate()` tidak me-re-SELECT baris
     * setelah `create()`, jadi kolom yang tidak disebut eksplisit di
     * array kedua akan tetap UNSET (bukan cuma null) pada instance
     * in-memory yang dikembalikan, meski nilai DEFAULT-nya sendiri
     * ter-apply di level DB — gotcha yang sama dengan
     * `WhatsappGatewaySettings::current()`, dikonfirmasi lewat docblock
     * method itu sebelum menulis ini.
     */
    public static function forTenant(int $tenantId): self
    {
        return static::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            [
                'dispatch_offset_minutes' => 120,
                'reminder_time' => '08:00',
                'command_interval_minutes' => 15,
                'wa_group_name' => null,
                'wa_group_jid' => null,
                'last_dispatch_run_at' => null,
            ]
        );
    }
}
