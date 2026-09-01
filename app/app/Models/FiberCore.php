<?php

namespace App\Models;

use App\Enums\FiberCoreStatus;
use Database\Factories\FiberCoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * v0.16.0 — one row per physical core inside a tube inside a FiberCable.
 * tube_color/core_color are populated at creation time by
 * App\Services\Network\FiberTopologyService::createCable() via
 * FiberColorService (TIA/EIA-598-C 12-color cycle) when no override was
 * given — they're plain nullable columns here, not computed on read.
 */
class FiberCore extends Model
{
    /** @use HasFactory<FiberCoreFactory> */
    use HasFactory;

    protected $fillable = [
        'fiber_cable_id',
        'tube_number',
        'core_number_in_tube',
        'tube_color',
        'core_color',
        'status',
        'port_number',
        'olt_device_id',
        'olt_pon_port_label',
    ];

    protected function casts(): array
    {
        return [
            'tube_number' => 'integer',
            'core_number_in_tube' => 'integer',
            'port_number' => 'integer',
            'olt_device_id' => 'integer',
            'status' => FiberCoreStatus::class,
        ];
    }

    public function fiberCable(): BelongsTo
    {
        return $this->belongsTo(FiberCable::class);
    }

    /**
     * v0.16.0 Langkah 7 — set when this core's OTB port is patched
     * straight into an OLT PON port rather than a downstream node.
     */
    public function oltDevice(): BelongsTo
    {
        return $this->belongsTo(OltDevice::class);
    }

    public function portLogs(): HasMany
    {
        return $this->hasMany(FiberCorePortLog::class);
    }
}
