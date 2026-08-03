<?php

namespace App\Models;

use App\Enums\WebhookProcessingResult;
use Illuminate\Database\Eloquent\Model;

/**
 * No BelongsToTenant — see the migration's comment: this logs every inbound
 * webhook attempt (including ones we can't attribute to a tenant, e.g.
 * rejected-signature payloads), a platform-wide audit trail, not
 * tenant-scoped business data.
 */
class PaymentWebhookLog extends Model
{
    protected $fillable = [
        'xendit_event_id',
        'payload',
        'signature_valid',
        'processed_at',
        'processing_result',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'processed_at' => 'datetime',
            'processing_result' => WebhookProcessingResult::class,
        ];
    }
}
