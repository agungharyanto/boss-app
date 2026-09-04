<?php

namespace App\Enums;

/**
 * Sprint "perpanjang-daftar-pelanggan" (tracking setoran) — status setoran
 * uang "titip" yang dipegang Referrer.
 *
 * Model default (dikonfirmasi Agung): Referrer memegang uang PENUH dari
 * pelanggan (`commission_ledger.gross_amount`, diambil dari `sell_price`
 * paket saat Perpanjang), menyetor semuanya ke admin, lalu komisi
 * (`commission_ledger.amount`) dibayar balik terpisah — Referrer TIDAK
 * memotong komisinya sendiri di tempat.
 *
 * Hanya relevan untuk baris `scheme = titip`. NULL untuk skema lain.
 */
enum TitipDepositStatus: string
{
    case BelumSetor = 'belum_setor';
    case SudahSetor = 'sudah_setor';

    public function label(): string
    {
        return match ($this) {
            self::BelumSetor => 'Belum Setor',
            self::SudahSetor => 'Sudah Setor',
        };
    }
}
