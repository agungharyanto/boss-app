<?php

namespace App\Models;

use App\Enums\CpeActionStatus;
use App\Enums\CpeActionType;
use App\Models\Concerns\BelongsToResellerScope;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CpeActionLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail for remote actions (reboot, WiFi credential change) sent to a
 * CPE device via GenieACS (v0.7.4). tenant_id/reseller_id are denormalized
 * from the target CpeDevice at write time, same rationale as
 * WhatsappMessageLog — cheap reseller-scoped history queries without a join.
 */
class CpeActionLog extends Model
{
    /** @use HasFactory<CpeActionLogFactory> */
    use BelongsToResellerScope, BelongsToTenant, HasFactory;

    protected $fillable = [
        'cpe_device_id',
        'tenant_id',
        'reseller_id',
        'performed_by',
        'action_type',
        'parameters',
        'genieacs_task_id',
        'status',
        'failed_reason',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'action_type' => CpeActionType::class,
            'parameters' => 'array',
            'status' => CpeActionStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    public function cpeDevice(): BelongsTo
    {
        return $this->belongsTo(CpeDevice::class);
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
