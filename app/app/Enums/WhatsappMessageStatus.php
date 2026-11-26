<?php

namespace App\Enums;

enum WhatsappMessageStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Delivered = 'delivered';
    // v0.28.4 (SEBAR-BATCH) — kirim otomatis pelanggan yang SENGAJA tidak jadi
    // dikirim: dibatalkan saat recheck D4 (syarat berubah sejak di-queue) atau
    // kedaluwarsa pembersih A3 (tak terkirim dalam jendela). BEDA dari Failed
    // (upaya kirim gagal). Log Cancelled TIDAK dihitung plafon & TIDAK dihitung
    // dedup (scope `effective()`), sehingga kandidatnya bisa dikumpulkan ulang
    // orkestrator (D5). Tanpa migrasi — kolom `status` = string + enum-cast.
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Antri',
            self::Sent => 'Terkirim',
            self::Failed => 'Gagal',
            self::Delivered => 'Diterima',
            self::Cancelled => 'Batal',
        };
    }
}
