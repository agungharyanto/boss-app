<?php

namespace App\Enums;

enum RegistrationStatus: string
{
    case Registered = 'registered';
    case Installed = 'installed';
    case Active = 'active';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Registered',
            self::Installed => 'Installed',
            self::Active => 'Active',
        };
    }
}
