<?php

namespace App\Models;

use App\Enums\OnuRegistryStatus;
use App\Enums\OnuSyncStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\OnuRegistryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cache hasil lookup ONU terakhir (v0.23.4) — ON-DEMAND SAJA, TIDAK
 * PERNAH jadi sumber kebenaran untuk operasi tulis. Lihat
 * docs/omci/onu-registry-design.md untuk desain lengkap dan
 * App\Services\Network\OnuRegistryService untuk satu-satunya cara resmi
 * baris di sini dibuat/diupdate.
 */
class OnuRegistry extends Model
{
    /** @use HasFactory<OnuRegistryFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'onu_registries';

    protected $fillable = [
        'tenant_id',
        'olt_device_id',
        'vendor_identifier',
        'serial_number',
        'mac_address',
        'status',
        'sync_status',
        'name',
        'description',
        'work_order_modem_unit_id',
        'last_synced_at',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'status' => OnuRegistryStatus::class,
            'sync_status' => OnuSyncStatus::class,
            'last_synced_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function oltDevice(): BelongsTo
    {
        return $this->belongsTo(OltDevice::class);
    }

    public function workOrderModemUnit(): BelongsTo
    {
        return $this->belongsTo(WorkOrderModemUnit::class);
    }
}
