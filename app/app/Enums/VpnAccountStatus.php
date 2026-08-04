<?php

namespace App\Enums;

enum VpnAccountStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Revoked => 'Dicabut',
            self::Expired => 'Kedaluwarsa',
        };
    }
}
