<?php

namespace App\Models;

use Database\Factories\WorkOrderModemUnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.13.4.1 — modem WAJIB per-unit (serial_number + mac_address), lihat
 * migration's own docblock. Akan dipakai ULANG di v0.13.4.2 (Aktivasi),
 * dan dipakai App\Services\Network\OnuRegistryService::findWorkOrderMatch()
 * (v0.23.4) untuk mencocokkan ONU yang di-sync dari OLT. Tidak ada
 * tenant_id sendiri — scoped implisit lewat work_order_id.
 */
class WorkOrderModemUnit extends Model
{
    /** @use HasFactory<WorkOrderModemUnitFactory> */
    use HasFactory;

    protected $fillable = [
        'work_order_id',
        'technician_id',
        'serial_number',
        'mac_address',
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
