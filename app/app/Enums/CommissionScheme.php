<?php

namespace App\Enums;

/**
 * v0.9.4 — skema komisi yang dipilih untuk satu atribusi referral
 * (`commission_ledger.scheme`). Nilainya diambil dari `commission_rates`
 * milik paket PPP yang dipilih pelanggan:
 *  - Recurring     → `commission_rates.recurring_amount` (Komisi Per Bulan)
 *  - LimitedCount  → `commission_rates.limited_count_amount` (skema X-kali)
 *  - Titip         → `commission_rates.titip_amount` (Komisi Titip)
 *
 * v0.9.6 — `Titip` ditambahkan. BEDA sifat dari dua skema lain: Recurring /
 * LimitedCount adalah skema *atribusi* pelanggan (dipilih admin saat
 * registrasi / set referrer, lalu dimatangkan otomatis oleh
 * `CommissionLedgerMaturityService` setiap invoice lunas). `Titip` TIDAK
 * pernah lewat jalur itu — baris `commission_ledger` scheme=titip dibuat
 * lewat aksi "Perpanjang" di Daftar Pelanggan (verifikasi OTP WhatsApp ke
 * acting Referrer, hanya untuk Referrer Sales/Freelance — lihat
 * `App\Services\Commission\SubscriptionRenewalService` +
 * `App\Services\Commission\ReferrerTitipService`). Karena itu
 * `CommissionLedgerMaturityService` secara eksplisit mengabaikan baris
 * scheme=titip (tidak pernah jadi "template", tidak pernah di-append per
 * invoice).
 */
enum CommissionScheme: string
{
    case Recurring = 'recurring';
    case LimitedCount = 'limited_count';
    case Titip = 'titip';

    public function label(): string
    {
        return match ($this) {
            self::Recurring => 'Per Bulan',
            self::LimitedCount => 'X-Kali',
            self::Titip => 'Titip',
        };
    }
}
