<?php

namespace App\Enums;

enum WhatsappSessionStatus: string
{
    case QrPending = 'qr_pending';
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case LoggedOut = 'logged_out';

    public function label(): string
    {
        return match ($this) {
            self::QrPending => 'Menunggu Scan QR',
            self::Connected => 'Terhubung',
            self::Disconnected => 'Terputus',
            self::LoggedOut => 'Logout',
        };
    }

    /**
     * Any status other than Connected means whatsapp:check-session-health
     * should keep a persistent dashboard alert showing for this session.
     */
    public function needsAlert(): bool
    {
        return $this !== self::Connected;
    }
}
