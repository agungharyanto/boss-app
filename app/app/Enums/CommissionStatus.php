<?php

namespace App\Enums;

enum CommissionStatus: string
{
    case Pending = 'pending';
    case Eligible = 'eligible';
    case Approved = 'approved';
    case Paid = 'paid';
    case Rejected = 'rejected';

    /**
     * v0.9.0 — baris "pembatalan" (reversal). Selalu punya `amount` negatif
     * dan `reversal_of_id` menunjuk baris asli. Baris asli tetap utuh
     * (append-only) — status Clawback HANYA dipakai baris reversal-nya.
     */
    case Clawback = 'clawback';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Eligible => 'Eligible',
            self::Approved => 'Approved',
            self::Paid => 'Paid',
            self::Rejected => 'Rejected',
            self::Clawback => 'Clawback',
        };
    }
}
