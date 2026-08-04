<?php

namespace App\Enums;

enum VpnServerStatus: string
{
    case Online = 'online';
    case Full = 'full';
    case Offline = 'offline';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Full => 'Penuh',
            self::Offline => 'Offline',
        };
    }
}
