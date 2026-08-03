<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Platform-level singleton (v0.3.5 Fase H) — see the create migration's
 * docblock for why there's no DB-level uniqueness guard: only
 * App\Services\Payment\PaymentGatewaySettingsService is expected to touch
 * this model, always targeting a single fixed row (id=1).
 */
class PaymentGatewaySettings extends Model
{
    protected $fillable = [
        'xendit_secret_key',
        'xendit_webhook_token',
        'is_configured',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'xendit_secret_key' => 'encrypted',
            'xendit_webhook_token' => 'encrypted',
            'is_configured' => 'boolean',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
