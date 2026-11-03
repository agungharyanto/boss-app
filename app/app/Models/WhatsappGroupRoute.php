<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.13.4.4 — routing kategori notifikasi -> grup. `category` = nilai
 * WhatsappEventType (string), didaftar di WhatsappGroupCategory. `group_id`
 * null (atau tidak ada baris) -> resolver pakai grup DEFAULT. Hapus grup ->
 * `group_id` jadi null (nullOnDelete) -> jatuh ke default.
 *
 * TIDAK pakai `BelongsToTenant` — sama alasan WhatsappNotificationGroup
 * (dipakai dari context tanpa Auth, query tenant_id eksplisit).
 */
class WhatsappGroupRoute extends Model
{
    protected $fillable = [
        'tenant_id',
        'category',
        'group_id',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(WhatsappNotificationGroup::class, 'group_id');
    }
}
