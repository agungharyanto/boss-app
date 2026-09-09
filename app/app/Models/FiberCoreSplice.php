<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * v0.16.1 Bagian B — one 1:1 core-to-core through-splice at a passive
 * node. See the create_fiber_core_splices migration for the schema
 * rationale (polymorphic node so ODP is covered; per-column UNIQUE as a
 * backstop, the real one-splice-per-core rule lives in
 * FiberCoreSpliceService).
 */
class FiberCoreSplice extends Model
{
    protected $fillable = [
        'splice_node_type',
        'splice_node_id',
        'from_fiber_core_id',
        'to_fiber_core_id',
        'loss_db',
        'note',
        'performed_by',
    ];

    protected function casts(): array
    {
        return [
            'loss_db' => 'decimal:2',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function spliceNode(): MorphTo
    {
        return $this->morphTo('splice_node');
    }

    public function fromCore(): BelongsTo
    {
        return $this->belongsTo(FiberCore::class, 'from_fiber_core_id');
    }

    public function toCore(): BelongsTo
    {
        return $this->belongsTo(FiberCore::class, 'to_fiber_core_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
