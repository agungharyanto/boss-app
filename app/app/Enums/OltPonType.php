<?php

namespace App\Enums;

enum OltPonType: string
{
    case Gpon = 'gpon';
    case Epon = 'epon';
    case GponEpon = 'gpon_epon';

    public function label(): string
    {
        return match ($this) {
            self::Gpon => 'GPON',
            self::Epon => 'EPON',
            self::GponEpon => 'GPON + EPON',
        };
    }
}
