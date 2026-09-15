<?php

namespace App\Enums;

/**
 * v0.13.2 — arah baris whatsapp_conversation_logs. Inbound/Outbound untuk
 * pesan sungguhan (ditulis pemanggil v0.13.3+ yang genuinely punya isi
 * pesan, lewat WhatsappConversationStateService::logMessage()). System
 * untuk event open()/close() itu sendiri, ditulis OTOMATIS oleh
 * WhatsappConversationStateService — content-nya deskripsi singkat event,
 * bukan isi pesan WhatsApp.
 */
enum WhatsappConversationDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Inbound => 'Masuk',
            self::Outbound => 'Keluar',
            self::System => 'Sistem',
        };
    }
}
