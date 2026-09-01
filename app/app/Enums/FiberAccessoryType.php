<?php

namespace App\Enums;

enum FiberAccessoryType: string
{
    case PinAdaptor = 'pin_adaptor';
    case Connector = 'connector';
    case SpliceFusion = 'splice_fusion';
    case SpliceMechanical = 'splice_mechanical';

    public function label(): string
    {
        return match ($this) {
            self::PinAdaptor => 'Pin Adaptor',
            self::Connector => 'Connector',
            self::SpliceFusion => 'Splice Fusion',
            self::SpliceMechanical => 'Splice Mechanical',
        };
    }
}
