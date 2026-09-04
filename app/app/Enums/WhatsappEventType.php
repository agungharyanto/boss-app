<?php

namespace App\Enums;

enum WhatsappEventType: string
{
    case InvoiceDueReminder = 'invoice_due_reminder';
    case PaymentReceived = 'payment_received';
    case CustomerRegistered = 'customer_registered';
    case CustomerSuspendedReminder = 'customer_suspended_reminder';

    /**
     * v0.9.6 — kode OTP verifikasi aksi Referrer (mis. mencatat titip
     * pembayaran lewat Portal Referrer). BEDA dari 4 event lain: penerimanya
     * REFERRER (`referrers.phone`), bukan pelanggan — dikirim lewat jalur
     * WhatsappGatewayService::buildAndQueueForReferrer(), bukan
     * buildAndQueue() yang terikat Customer. Explicit permission dari Agung
     * saat sprint v0.9.6 (event type ke-5 pertama sejak topologi 4-tipe
     * dikunci di v0.4.0).
     */
    case ReferrerActionOtp = 'referrer_action_otp';

    public function label(): string
    {
        return match ($this) {
            self::InvoiceDueReminder => 'Pengingat Jatuh Tempo Invoice',
            self::PaymentReceived => 'Pembayaran Diterima',
            self::CustomerRegistered => 'Pelanggan Terdaftar',
            self::CustomerSuspendedReminder => 'Pengingat Pelanggan Suspend',
            self::ReferrerActionOtp => 'Kode OTP Aksi Referrer',
        };
    }
}
