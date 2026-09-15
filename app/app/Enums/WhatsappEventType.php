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

    /**
     * 2026-09-15 — dikirim ke NOMOR WA FISIK itu sendiri (bukan Customer/
     * Referrer/Technician), lewat SESSION LAMA yang masih aktif, saat
     * WhatsappSessionService::applyStatus() menolak percobaan pairing
     * kedua nomor yang sama ke session BOSS App lain. Penerima non-entitas
     * seperti ReferrerActionOtp — reuse WhatsappGatewayService::
     * buildAndQueueForRecipient(), TAPI dengan $resellerId eksplisit
     * (session LAMA milik reseller mana pun, BUKAN selalu 'direct' seperti
     * ReferrerActionOtp) supaya pesan genuinely terkirim lewat WhatsApp
     * yang benar-benar masih terhubung ke nomor itu.
     */
    case DuplicateSessionAttempt = 'duplicate_session_attempt';

    public function label(): string
    {
        return match ($this) {
            self::InvoiceDueReminder => 'Pengingat Jatuh Tempo Invoice',
            self::PaymentReceived => 'Pembayaran Diterima',
            self::CustomerRegistered => 'Pelanggan Terdaftar',
            self::CustomerSuspendedReminder => 'Pengingat Pelanggan Suspend',
            self::ReferrerActionOtp => 'Kode OTP Aksi Referrer',
            self::DuplicateSessionAttempt => 'Percobaan Pairing Sesi Duplikat',
        };
    }
}
