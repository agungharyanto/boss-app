<?php

namespace App\Models;

use App\Enums\OdpPortStatus;
use Database\Factories\OdpPortFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OdpPort extends Model
{
    /** @use HasFactory<OdpPortFactory> */
    use HasFactory;

    protected $fillable = [
        'odp_id',
        'port_number',
        'status',
        'subscription_id',
    ];

    protected function casts(): array
    {
        return [
            'port_number' => 'integer',
            'status' => OdpPortStatus::class,
        ];
    }

    public function odp(): BelongsTo
    {
        return $this->belongsTo(Odp::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }
}
