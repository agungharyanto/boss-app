<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.13.4.3.1 — tanda tangan pelanggan + snapshot persyaratan yang
 * disetujui saat penutupan WO via form web. `file_path` di disk privat.
 * TIDAK pakai BelongsToTenant — di-scope lewat work_order (signed-link,
 * tanpa Auth). Append-only secara praktik (satu baris per WO, unique).
 */
class WorkOrderSignature extends Model
{
    protected $fillable = [
        'work_order_id',
        'file_path',
        'signed_at',
        'terms_version',
        'terms_snapshot',
        'signed_ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
