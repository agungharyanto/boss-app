<?php

namespace App\Enums;

enum ReferrerType: string
{
    case Sales = 'sales';
    case Teknisi = 'teknisi';
    case Freelance = 'freelance';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'Sales',
            self::Teknisi => 'Teknisi',
            self::Freelance => 'Freelance',
            self::Admin => 'Admin',
        };
    }
}
