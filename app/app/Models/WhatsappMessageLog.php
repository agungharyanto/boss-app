<?php

namespace App\Models;

use App\Enums\WhatsappEventType;
use App\Enums\WhatsappMessageStatus;
use App\Models\Concerns\BelongsToResellerScope;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\WhatsappMessageLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappMessageLog extends Model
{
    /** @use HasFactory<WhatsappMessageLogFactory> */
    use BelongsToResellerScope, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'customer_id',
        'invoice_id',
        'phone_number',
        'event_type',
        'template_id',
        'rendered_content',
        'status',
        'failed_reason',
        'attempts',
        'queued_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => WhatsappEventType::class,
            'status' => WhatsappMessageStatus::class,
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsappMessageTemplate::class, 'template_id');
    }
}
