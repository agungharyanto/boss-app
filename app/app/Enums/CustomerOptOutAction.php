<?php

namespace App\Enums;

/**
 * v0.28.1 (T1) — aksi pada jejak opt-out notifikasi otomatis pelanggan.
 */
enum CustomerOptOutAction: string
{
    case OptedOut = 'opted_out';
    case OptedIn = 'opted_in';

    public function label(): string
    {
        return match ($this) {
            self::OptedOut => 'Opt-out (berhenti notifikasi otomatis)',
            self::OptedIn => 'Opt-in (menerima notifikasi otomatis lagi)',
        };
    }
}
