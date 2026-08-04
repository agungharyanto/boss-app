<?php

namespace App\Enums;

enum WorkOrderDeviceType: string
{
    case Ont = 'ont';
    case Router = 'router';
    case Ap = 'ap';

    public function label(): string
    {
        return match ($this) {
            self::Ont => 'ONT',
            self::Router => 'Router',
            self::Ap => 'Access Point',
        };
    }
}
