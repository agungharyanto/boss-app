<?php

namespace App\Models;

use Database\Factories\CpeSignalHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (CpeDevice, poll) — v0.8.3, written by App\Console\Commands\
 * SyncCpeSignalHistory every ~20 minutes for every currently-online CpeDevice.
 * Unlike CpeConnectedHost (upsert-in-place, one row per MAC ever seen), this
 * is genuinely append-only — one NEW row every poll, by design, since the
 * whole point is a time-series for the history graph. `rx_power_dbm` null
 * means the poll ran but couldn't get a reading (refresh failure, or no
 * cpe_parameter_maps row for this device's model) — a real gap, not a
 * missing row. See the migration and SyncCpeSignalHistory's own docblocks.
 */
class CpeSignalHistory extends Model
{
    /** @use HasFactory<CpeSignalHistoryFactory> */
    use HasFactory;

    // Eloquent's default pluralization would guess "cpe_signal_histories" —
    // real bug hit running this for real: the migration (matching the
    // requested table name exactly) creates "cpe_signal_history" instead,
    // singular. Caught by the very first real insert failing outright
    // (SQLSTATE 42P01 "Undefined table"), not by the test suite — SQLite
    // and Postgres both would have failed the same way, but no test had
    // exercised a real INSERT against a real migrated schema yet at that
    // point.
    protected $table = 'cpe_signal_history';

    public $timestamps = false;

    protected $fillable = [
        'cpe_device_id',
        'rx_power_dbm',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'rx_power_dbm' => 'float',
            'recorded_at' => 'datetime',
        ];
    }

    public function cpeDevice(): BelongsTo
    {
        return $this->belongsTo(CpeDevice::class);
    }
}
