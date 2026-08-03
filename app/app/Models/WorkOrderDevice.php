<?php

namespace App\Models;

use App\Enums\WorkOrderDeviceType;
use Database\Factories\WorkOrderDeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderDevice extends Model
{
    /** @use HasFactory<WorkOrderDeviceFactory> */
    use HasFactory;

    protected $fillable = [
        'work_order_id',
        'device_type',
        'mac_address',
        'serial_number',
        'scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'device_type' => WorkOrderDeviceType::class,
            'scanned_at' => 'datetime',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
