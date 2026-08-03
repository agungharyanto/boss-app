<?php

namespace App\Enums;

enum RemittanceStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
    case Remitted = 'remitted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Finalized => 'Finalized',
            self::Remitted => 'Remitted',
        };
    }
}
