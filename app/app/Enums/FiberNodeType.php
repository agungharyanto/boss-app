<?php

namespace App\Enums;

enum FiberNodeType: string
{
    case Otb = 'otb';
    case Closure = 'closure';
    case Odc = 'odc';

    public function label(): string
    {
        return match ($this) {
            self::Otb => 'OTB',
            self::Closure => 'Closure',
            self::Odc => 'ODC',
        };
    }
}
