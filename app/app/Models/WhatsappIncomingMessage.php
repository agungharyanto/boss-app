<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.13.1 — baris mentah pesan masuk WhatsApp, ditulis oleh
 * WhatsappWebhookController::incomingMessage() (webhook publik, whatsapp-
 * gateway/internal/webhook.NotifyIncomingMessage). TIDAK tenant-scoped
 * (lihat docblock migration-nya) — resolusi ke customer/technician/tenant
 * adalah business logic v0.13.2+, di luar scope model ini.
 *
 * `reseller_id` (perluasan, migration `2026_09_14_110000_...`) TIDAK
 * dikirim dari sisi Go sama sekali — di-resolve di
 * WhatsappIncomingMessageService::recordFromWebhook() dari `session_key`
 * ('direct' -> null, numerik -> reseller_id itu kalau reseller-nya
 * genuinely masih ada). Nullable FK polos, TANPA constraint unique apa
 * pun — pola sama `whatsapp_message_logs.reseller_id`, bukan pola
 * partial-unique-index `reseller_tax_policies` (tidak relevan di sini,
 * banyak baris boleh sama-sama satu reseller).
 *
 * `is_lid` (fix bug LID, migration `2026_09_15_090000_...`) — DIKIRIM
 * eksplisit dari sisi Go (whatsapp-gateway/internal/session/
 * manager.go::resolveSenderPhone()). true berarti `sender_phone` di baris
 * ini adalah raw WhatsApp LID (Linked ID) apa adanya, BUKAN nomor telepon
 * — resolusi ke PN asli gagal di semua fallback (SenderAlt kosong DAN
 * tidak ada mapping tersimpan di LIDStore lokal whatsmeow). false berarti
 * `sender_phone` genuinely nomor telepon format lokal "0xxx".
 */
class WhatsappIncomingMessage extends Model
{
    protected $fillable = [
        'session_key',
        'reseller_id',
        'sender_phone',
        'is_lid',
        'chat_jid',
        'message_id',
        'text',
        'push_name',
        'received_at',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    protected function casts(): array
    {
        return [
            'is_lid' => 'boolean',
            'received_at' => 'datetime',
        ];
    }
}
