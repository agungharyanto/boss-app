<?php

namespace App\Enums;

enum PaymentGatewayChannelCategory: string
{
    case BankTransferVa = 'bank_transfer_va';
    case Ewallet = 'ewallet';
    case RetailOutlet = 'retail_outlet';
    case CreditCard = 'credit_card';
    case Qris = 'qris';
    case Invoice = 'invoice';

    public function label(): string
    {
        return match ($this) {
            self::BankTransferVa => 'Virtual Account Bank',
            self::Ewallet => 'E-Wallet',
            self::RetailOutlet => 'Retail Outlet',
            self::CreditCard => 'Credit Card',
            self::Qris => 'QRIS',
            self::Invoice => 'Xendit Invoice',
        };
    }
}
