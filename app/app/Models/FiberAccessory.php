<?php

namespace App\Models;

use App\Enums\FiberAccessoryType;
use Database\Factories\FiberAccessoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.16.0 — an accessory (pin adaptor/connector/splice) attached to
 * EITHER a FiberCable OR a Splitter — never a morph (only 2 possible
 * targets, both already real tables with their own FK), just two
 * nullable FKs. "exactly one of the two must be set" is validated in
 * App\Services\Network\FiberTopologyService, not a DB constraint (a
 * portable XOR CHECK constraint isn't trivial across SQLite/Postgres,
 * same reasoning as fiber_cables.total_cores' even-only rule).
 */
class FiberAccessory extends Model
{
    /** @use HasFactory<FiberAccessoryFactory> */
    use HasFactory;

    protected $fillable = [
        'fiber_cable_id',
        'splitter_id',
        'accessory_type',
        'expected_loss_db',
        'measured_loss_db',
        'location_note',
    ];

    protected function casts(): array
    {
        return [
            'accessory_type' => FiberAccessoryType::class,
            'expected_loss_db' => 'decimal:2',
            'measured_loss_db' => 'decimal:2',
        ];
    }

    public function fiberCable(): BelongsTo
    {
        return $this->belongsTo(FiberCable::class);
    }

    public function splitter(): BelongsTo
    {
        return $this->belongsTo(Splitter::class);
    }
}
