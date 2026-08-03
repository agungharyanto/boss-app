<?php

namespace App\Enums;

enum TaxComponentType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage',
            self::Fixed => 'Fixed',
        };
    }
}
