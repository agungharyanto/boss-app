<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\FiberCableFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * v0.16.0 — a cable segment between two topology points (from/to, each
 * morphs to FiberNode OR Odp). total_cores' even-only rule and the
 * from/to endpoints being genuinely different rows are validated in
 * App\Services\Network\FiberTopologyService, not here or at the DB level
 * — see that service's own docblock.
 */
class FiberCable extends Model
{
    /** @use HasFactory<FiberCableFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'from_type',
        'from_id',
        'to_type',
        'to_id',
        'total_cores',
        'tube_count',
        'cores_per_tube',
    ];

    protected function casts(): array
    {
        return [
            'total_cores' => 'integer',
            'tube_count' => 'integer',
            'cores_per_tube' => 'integer',
        ];
    }

    public function from(): MorphTo
    {
        return $this->morphTo();
    }

    public function to(): MorphTo
    {
        return $this->morphTo();
    }

    public function cores(): HasMany
    {
        return $this->hasMany(FiberCore::class);
    }

    public function accessories(): HasMany
    {
        return $this->hasMany(FiberAccessory::class);
    }
}
