<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform-level singleton (id=1) — one Baileys rate-limit policy for the
 * whole ISP deployment, same posture as App\Models\PaymentGatewaySettings.
 * Only App\Services\Whatsapp\WhatsappSessionService's rate-limit reader (and
 * the ISP-admin settings Livewire component) are expected to touch this.
 */
class WhatsappGatewaySettings extends Model
{
    public const SINGLETON_ID = 1;

    protected $fillable = [
        'rate_limit_delay_min_seconds',
        'rate_limit_delay_max_seconds',
        'rate_limit_batch_size',
        'rate_limit_batch_pause_min_minutes',
        'rate_limit_batch_pause_max_minutes',
        'daily_schedule_times',
    ];

    protected function casts(): array
    {
        return [
            'daily_schedule_times' => 'array',
        ];
    }

    /**
     * All defaults are passed explicitly here rather than relying on the
     * migration's DB-level column defaults — Eloquent's create() does not
     * re-SELECT the row afterwards, so any column left out of $values would
     * stay entirely unset (not merely null) on the in-memory instance even
     * though the DB row itself got its DEFAULT applied. That gap was silent
     * until the settings form actually read these into typed `int`
     * properties and threw "Cannot assign null to typed property".
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['id' => self::SINGLETON_ID],
            [
                'rate_limit_delay_min_seconds' => 5,
                'rate_limit_delay_max_seconds' => 10,
                'rate_limit_batch_size' => 20,
                'rate_limit_batch_pause_min_minutes' => 5,
                'rate_limit_batch_pause_max_minutes' => 10,
                'daily_schedule_times' => ['08:00', '20:00'],
            ]
        );
    }
}
