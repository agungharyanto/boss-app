<?php

namespace App\Enums;

/**
 * v0.13.5.4 — metode pembayaran yang bisa ditampilkan di invoice. Dipakai untuk
 * label saklar + struktur PaymentInstructionsService. Kolom payments.method
 * (mencatat metode saat lunas) DITUNDA ke v0.13.5.5 (K7).
 */
enum PaymentMethod: string
{
    case ManualTransfer = 'manual_transfer';
    case PaymentGateway = 'payment_gateway';
    case Agent = 'agent';

    public function label(): string
    {
        return match ($this) {
            self::ManualTransfer => 'Transfer Manual',
            self::PaymentGateway => 'Payment Gateway (Xendit)',
            self::Agent => 'Bayar via Agen',
        };
    }
}
