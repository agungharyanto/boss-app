<?php

namespace App\Enums;

/**
 * v0.9.4 — skema komisi yang dipilih untuk satu atribusi referral
 * (`commission_ledger.scheme`). Nilainya diambil dari `commission_rates`
 * milik paket PPP yang dipilih pelanggan:
 *  - Recurring     → `commission_rates.recurring_amount` (Komisi Per Bulan)
 *  - LimitedCount  → `commission_rates.limited_count_amount` (skema X-kali)
 *
 * "Titip" SENGAJA tidak ada di sini — mekanisme terpisah, di luar scope
 * v0.9.4 (lihat CommissionRate + CLAUDE.md).
 */
enum CommissionScheme: string
{
    case Recurring = 'recurring';
    case LimitedCount = 'limited_count';

    public function label(): string
    {
        return match ($this) {
            self::Recurring => 'Per Bulan',
            self::LimitedCount => 'X-Kali',
        };
    }
}
