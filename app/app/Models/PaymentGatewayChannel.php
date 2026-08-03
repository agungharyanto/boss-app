<?php

namespace App\Models;

use App\Enums\PaymentGatewayChannelCategory;
use Database\Factories\PaymentGatewayChannelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin-managed catalog of Xendit channels (v0.3.5 Fase H) — replaces the
 * old fixed 3-case App\Enums\PaymentChannelType. Platform-wide, not
 * tenant-scoped (one Xendit account for the whole ISP, same posture as
 * PaymentGatewaySettings).
 */
class PaymentGatewayChannel extends Model
{
    /** @use HasFactory<PaymentGatewayChannelFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'label',
        'category',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'category' => PaymentGatewayChannelCategory::class,
            'enabled' => 'boolean',
        ];
    }

    public static function labelFor(string $code): string
    {
        return static::where('code', $code)->value('label') ?? $code;
    }
}
