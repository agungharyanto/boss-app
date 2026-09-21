<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.13.4.1 — lihat migration's own docblock untuk kenapa ini TERPISAH dari
 * WorkOrderTechnician (v0.12.3). Tidak ada tenant_id/reseller_id sendiri —
 * scoped implisit lewat work_order_id, pola sama WorkOrderTechnician.
 */
class WorkOrderClaimPartner extends Model
{
    protected $fillable = [
        'work_order_id',
        'technician_id',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }
}
