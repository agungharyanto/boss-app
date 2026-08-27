<?php

namespace App\Enums;

enum NetworkProfileGroupType: string
{
    case Hotspot = 'hotspot';
    case Ppp = 'ppp';

    public function label(): string
    {
        return match ($this) {
            self::Hotspot => 'Hotspot',
            self::Ppp => 'PPP',
        };
    }
}
