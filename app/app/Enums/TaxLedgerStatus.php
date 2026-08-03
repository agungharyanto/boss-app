<?php

namespace App\Enums;

enum TaxLedgerStatus: string
{
    case Pending = 'pending';
    case Remitted = 'remitted';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Remitted => 'Remitted',
            self::Voided => 'Voided',
        };
    }
}
