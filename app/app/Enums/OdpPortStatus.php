<?php

namespace App\Enums;

enum OdpPortStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Used = 'used';
    case Damaged = 'damaged';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Tersedia',
            self::Reserved => 'Dipesan',
            self::Used => 'Terpakai',
            self::Damaged => 'Rusak',
        };
    }
}
