<?php

namespace App\Enums;

enum WhatsappSessionStatus: string
{
    case QrPending = 'qr_pending';
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case LoggedOut = 'logged_out';

    /**
     * Nomor WA fisik yang baru saja pairing SUDAH terhubung di session
     * BOSS App lain (session_key berbeda) — WhatsappSessionService::
     * applyStatus() menolaknya SEBELUM disimpan sebagai `connected`, paksa
     * logout sisi gateway, dan kirim notifikasi WA lewat session lama yang
     * masih aktif. Reason lengkapnya ada di `whatsapp_sessions.status_reason`
     * (migration `2026_09_15_100000_...`) — label di bawah cuma ringkas,
     * UI menampilkan status_reason di sampingnya untuk penjelasan penuh.
     */
    case RejectedDuplicate = 'rejected_duplicate';

    public function label(): string
    {
        return match ($this) {
            self::QrPending => 'Menunggu Scan QR',
            self::Connected => 'Terhubung',
            self::Disconnected => 'Terputus',
            self::LoggedOut => 'Logout',
            self::RejectedDuplicate => 'Ditolak (Nomor Duplikat)',
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
