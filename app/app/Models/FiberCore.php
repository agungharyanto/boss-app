<?php

namespace App\Models;

use App\Enums\FiberCoreStatus;
use Database\Factories\FiberCoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    protected function casts(): array
    {
        return [
            'tube_number' => 'integer',
            'core_number_in_tube' => 'integer',
            'status' => FiberCoreStatus::class,
        ];
    }

    public function fiberCable(): BelongsTo
    {
        return $this->belongsTo(FiberCable::class);
    }
}
