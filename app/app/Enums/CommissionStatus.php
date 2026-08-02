<?php

namespace App\Enums;

enum CommissionStatus: string
{
    case Pending = 'pending';
    case Eligible = 'eligible';
    case Approved = 'approved';
    case Paid = 'paid';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Eligible => 'Eligible',
            self::Approved => 'Approved',
            self::Paid => 'Paid',
            self::Rejected => 'Rejected',
        };
    }
}
