<?php

namespace App\Enums;

enum WhatsappMessageStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Delivered = 'delivered';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Antri',
            self::Sent => 'Terkirim',
            self::Failed => 'Gagal',
            self::Delivered => 'Diterima',
        };
    }
}
