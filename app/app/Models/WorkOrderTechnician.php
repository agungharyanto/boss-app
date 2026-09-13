<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.12.3 — "klaim" WorkOrder oleh Technician lewat API. Lihat migration's
 * own docblock untuk kenapa ini GENUINELY terpisah dari
 * `work_orders.technician_id` (assignment resmi admin). Tidak ada factory
 * — baris di sini selalu dibuat lewat WorkOrderController::claim() (atau
 * dites langsung lewat itu / WorkOrderTechnician::create() manual), tidak
 * ada kebutuhan factory random seperti model domain lain.
 */
class WorkOrderTechnician extends Model
{
    protected $fillable = [
        'work_order_id',
        'technician_id',
        'claimed_at',
    ];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }
}
