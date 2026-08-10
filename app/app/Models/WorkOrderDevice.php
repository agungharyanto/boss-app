<?php

namespace App\Models;

use App\Enums\WorkOrderDeviceType;
use Database\Factories\WorkOrderDeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'ssid',
        'wifi_password',
    ];

    // Same posture as Nas::api_password/radius_secret — a real, retrievable
    // credential (needed later by CpeActionService), never serialized back
    // out by accident.
    protected $hidden = [
        'wifi_password',
    ];

    protected function casts(): array
    {
        return [
            'device_type' => WorkOrderDeviceType::class,
            'scanned_at' => 'datetime',
            'wifi_password' => 'encrypted',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * v0.7.5 — the CpeDevice this scan turned into, if binding has
     * happened yet (see App\Services\Network\CpeBindingService). Used by
     * WorkOrderShow to display auto-provisioning status
     * (`wifi_provisioned_at`) next to the ssid/wifi_password input.
     */
    public function cpeDevice(): HasOne
    {
        return $this->hasOne(CpeDevice::class);
    }
}
