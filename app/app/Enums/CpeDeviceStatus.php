<?php

namespace App\Enums;

enum CpeDeviceStatus: string
{
    case PendingFirstConnect = 'pending_first_connect';
    case Online = 'online';
    case Offline = 'offline';

    public function label(): string
    {
        return match ($this) {
            self::PendingFirstConnect => 'Menunggu Koneksi Pertama',
            self::Online => 'Online',
            self::Offline => 'Offline',
        };
    }
}
