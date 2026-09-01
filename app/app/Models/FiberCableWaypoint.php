<?php

namespace App\Models;

use Database\Factories\FiberCableWaypointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.16.0 Langkah 8 — one bend point on a fiber_cable's drawn route (see
 * the migration's own docblock). No tenant scope of its own — reached
 * only through FiberCable::waypoints(), which is already tenant-scoped
 * via the cable.
 */
class FiberCableWaypoint extends Model
{
    /** @use HasFactory<FiberCableWaypointFactory> */
    use HasFactory;

    protected $fillable = [
        'fiber_cable_id',
        'sequence',
        'latitude',
        'longitude',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function fiberCable(): BelongsTo
    {
        return $this->belongsTo(FiberCable::class);
    }
}
