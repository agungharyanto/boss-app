<?php

namespace App\Enums;

enum ContactAccessLevel: string
{
    case Full = 'full';
    case ViewOnly = 'view_only';
    case Emergency = 'emergency';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full Access',
            self::ViewOnly => 'View Only',
            self::Emergency => 'Emergency Only',
        };
    }
}
