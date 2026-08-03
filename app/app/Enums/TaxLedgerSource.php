<?php

namespace App\Enums;

enum TaxLedgerSource: string
{
    case Seeded = 'seeded';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Seeded => 'Seeded',
            self::System => 'System',
        };
    }
}
