<?php

namespace App\Enums;

enum NasStatus: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Offline => 'Offline',
            self::Unknown => 'Belum Diketahui',
        };
    }
}
