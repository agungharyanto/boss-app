<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * v0.13.4.4 — satu grup WA tujuan notifikasi, per tenant, terikat ke
 * `session_key` ('direct' | reseller_id string). SENGAJA TIDAK pakai
 * `BelongsToTenant` — resolver (WhatsappGroupRouter) dipanggil dari
 * command/queue/afterCommit tanpa Auth, jadi semua query memfilter
 * `tenant_id` eksplisit (pola sama WorkOrderDispatchSettings/StaffService).
 *
 * `is_default` dijaga unik per (tenant_id, session_key) oleh partial unique
 * index DB; aplikasi (Livewire) yang memastikan hanya 1 default aktif saat
 * set default (unset yang lain dulu).
 */
class WhatsappNotificationGroup extends Model
{
    protected $fillable = [
        'tenant_id',
        'session_key',
        'jid',
        'label',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function routes(): HasMany
    {
        return $this->hasMany(WhatsappGroupRoute::class, 'group_id');
    }
}
