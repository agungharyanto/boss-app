<?php

namespace App\Enums;

enum VpnIpPoolStatus: string
{
    case Available = 'available';
    case Assigned = 'assigned';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Tersedia',
            self::Assigned => 'Terpakai',
        };
    }
}
