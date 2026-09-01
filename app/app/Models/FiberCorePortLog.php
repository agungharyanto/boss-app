<?php

namespace App\Models;

use Database\Factories\FiberCorePortLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.16.0 Langkah 7 — one row per change to a FiberCore's OTB port
 * assignment (port_number or OLT link). Written by
 * App\Services\Network\FiberTopologyService::assignCorePort()/
 * assignCorePorts(). Append-only audit — never updated/deleted from the
 * app (same posture as cpe_action_logs).
 */
class FiberCorePortLog extends Model
{
    /** @use HasFactory<FiberCorePortLogFactory> */
    use HasFactory;

    protected $fillable = [
        'fiber_core_id',
        'fiber_node_id',
        'performed_by',
        'old_port_number',
        'new_port_number',
        'old_olt_label',
        'new_olt_label',
    ];

    protected function casts(): array
    {
        return [
            'old_port_number' => 'integer',
            'new_port_number' => 'integer',
        ];
    }

    public function fiberCore(): BelongsTo
    {
        return $this->belongsTo(FiberCore::class);
    }

    public function fiberNode(): BelongsTo
    {
        return $this->belongsTo(FiberNode::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
