<?php

namespace App\Models;

use Database\Factories\ContainerStatsHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per (container, poll) — v0.8.4 Bagian C, written by
 * App\Console\Commands\SyncContainerStats every 5 minutes for every
 * container docker-stats-proxy currently reports. Genuinely append-only,
 * same posture as CpeSignalHistory (v0.8.3) — every field nullable so a
 * single container's fetch failure still records a row (a real gap the
 * history view should be able to show), not a silently missing point.
 */
class ContainerStatsHistory extends Model
{
    /** @use HasFactory<ContainerStatsHistoryFactory> */
    use HasFactory;

    // Explicit, not left to Eloquent's guess — "container_stats_history"
    // pluralizes to "container_stats_histories", the exact same class of
    // mismatch already hit for real with CpeSignalHistory (v0.8.3, see that
    // model's own docblock) — set explicitly from the start this time
    // instead of waiting to rediscover the same bug.
    protected $table = 'container_stats_history';

    public $timestamps = false;

    protected $fillable = [
        'container_name',
        'cpu_percent',
        'memory_usage_mb',
        'memory_limit_mb',
        'network_rx_bytes',
        'network_tx_bytes',
        'disk_usage_mb',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'cpu_percent' => 'float',
            'memory_usage_mb' => 'float',
            'memory_limit_mb' => 'float',
            'network_rx_bytes' => 'integer',
            'network_tx_bytes' => 'integer',
            'disk_usage_mb' => 'float',
            'recorded_at' => 'datetime',
        ];
    }
}
