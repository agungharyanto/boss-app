<?php

namespace App\Enums;

enum TaxBurden: string
{
    case CustomerBorne = 'customer_borne';
    case ResellerBorne = 'reseller_borne';
    case Split = 'split';

    public function label(): string
    {
        return match ($this) {
            self::CustomerBorne => 'Customer Borne',
            self::ResellerBorne => 'Reseller Borne',
            self::Split => 'Split',
        };
    }
}
