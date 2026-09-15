<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * v0.13.1 — baris mentah pesan masuk WhatsApp, ditulis oleh
 * WhatsappWebhookController::incomingMessage() (webhook publik, whatsapp-
 * gateway/internal/webhook.NotifyIncomingMessage). TIDAK tenant-scoped
 * (lihat docblock migration-nya) — resolusi ke customer/technician/tenant
 * adalah business logic v0.13.2+, di luar scope model ini.
 */
class WhatsappIncomingMessage extends Model
{
    protected $fillable = [
        'session_key',
        'sender_phone',
        'chat_jid',
        'message_id',
        'text',
        'push_name',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }
}
