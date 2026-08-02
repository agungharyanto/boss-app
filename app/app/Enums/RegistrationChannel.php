<?php

namespace App\Enums;

enum RegistrationChannel: string
{
    case Admin = 'admin';
    case Sales = 'sales';
    case Teknisi = 'teknisi';
    case Freelance = 'freelance';
    case SelfService = 'self';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Sales => 'Sales',
            self::Teknisi => 'Teknisi',
            self::Freelance => 'Freelance',
            self::SelfService => 'Self-service',
        };
    }
}
