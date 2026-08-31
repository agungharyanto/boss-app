<?php

namespace App\Enums;

enum FiberCoreStatus: string
{
    case Used = 'used';
    case Spare = 'spare';

    public function label(): string
    {
        return match ($this) {
            self::Used => 'Terpakai',
            self::Spare => 'Cadangan',
        };
    }
}
