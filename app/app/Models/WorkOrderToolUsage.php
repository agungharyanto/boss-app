<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.13.4.1 — alat NON-modem dicatat dibawa untuk sebuah Work Order (lihat
 * migration's own docblock). Tidak ada tenant_id sendiri — scoped implisit
 * lewat work_order_id.
 */
class WorkOrderToolUsage extends Model
{
    protected $fillable = [
        'work_order_id',
        'tool_type_id',
        'technician_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function toolType(): BelongsTo
    {
        return $this->belongsTo(ToolType::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }
}
