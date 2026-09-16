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

    /**
     * v0.13.3 — pesan masuk dari nomor yang TERAUTORISASI sebagai teknisi
     * dengan WorkOrder aktif (`WhatsappTechnicianAuthService::
     * resolveAuthorizedTechnicianForActiveWorkOrder()`), TAPI belum ada
     * state percakapan aktif untuk nomor itu — business logic PSB
     * sesungguhnya belum ada (v0.13.4), jadi sengaja dibalas pesan
     * "fitur sedang dikembangkan", BUKAN disamakan dengan fallback
     * generik pesan tak dikenali (penerimanya JELAS teknisi sah, cuma
     * fiturnya belum siap — beda pesan dari "kamu siapa").
     */
    case TechnicianFeaturePending = 'technician_feature_pending';

    /**
     * v0.13.3 — fallback pesan masuk yang TIDAK match state percakapan
     * aktif manapun DAN TIDAK match otorisasi teknisi manapun. Satu-
     * satunya "saya tidak tahu harus ngapain dengan pesan ini" generik di
     * modul WhatsApp 2-Arah — dikirim lewat sesi "direct" selalu (bukan
     * konteks reseller tertentu, karena tidak ada entitas yang dikenali
     * sama sekali dari pesan ini).
     */
    case UnrecognizedMessageFallback = 'unrecognized_message_fallback';

    /**
     * v0.22.4 — pesan 1 dari 2 saat `StaffService::create()` berhasil:
     * teks penjelasan ("ini password login kamu"). Template BIASA (bisa
     * diedit admin lewat UI Template WA, seperti event type lain) —
     * dikirim lewat `WhatsappGatewayService::buildAndQueueForRecipient()`
     * SEPERTI BIASA. Pasangan `StaffInitialPasswordValue` di bawah adalah
     * pesan ke-2, SENGAJA event type TERPISAH (bukan digabung 1 pesan)
     * karena `buildAndQueueForRecipient()` cuma resolve SATU template per
     * event type per panggilan — 2 pesan = 2 event type.
     */
    case StaffInitialPasswordNotice = 'staff_initial_password_notice';

    /**
     * v0.22.4 — pesan 2 dari 2: password POLOS itu sendiri, tanpa
     * karakter/format apa pun menempel (biar gampang tap-hold copy di WA),
     * dikirim SEGERA setelah `StaffInitialPasswordNotice`. SENGAJA TIDAK
     * pernah melalui `WhatsappTemplateService::resolve()`/`render()` —
     * `WhatsappMessageLog` untuk event ini dibuat manual dengan
     * `template_id = null` + `rendered_content = $password` mentah
     * langsung di kode (`StaffService::sendInitialPasswordMessages()`) —
     * satu-satunya cara MENJAMIN SECARA STRUKTURAL isi pesan ini tetap
     * polos, karena kalau lewat template yang bisa diedit admin lewat UI
     * Template WA, tidak ada jaminan admin tidak menambah teks lain di
     * sekitar `{password}`. Konsekuensi: event type ini TIDAK PERNAH
     * muncul sebagai opsi yang bisa diedit di halaman Template WA (lihat
     * `WhatsappTemplateService`/seeder — sengaja tidak di-seed template
     * apa pun untuknya).
     */
    case StaffInitialPasswordValue = 'staff_initial_password_value';

    /**
     * v0.26.3 — WO "dispatch" (siap dikerjakan) ATAU reminder H+ untuk WO
     * yang belum selesai. Penerima: TEKNISI (`technicians.phone`), bukan
     * Customer/Referrer — dikirim lewat `WhatsappGatewayService::
     * buildAndQueueForRecipient()` seperti biasa (bukan jalur `Referrer`).
     *
     * SATU event type mencakup DUA situasi (dispatch awal vs reminder) —
     * `WhatsappTemplateService::resolve()` cuma resolve 1 template per
     * event_type, tidak ada sub-varian bawaan, jadi bedanya dituangkan
     * lewat variabel `{status_notice}` (isi beda tergantung `$isReminder`
     * di `WorkOrderDispatchService`), bukan 2 template terpisah — lihat
     * `WhatsappMessageTemplateSeeder`'s own default content.
     *
     * BROADCAST, bukan ke 1 penerima — keputusan Agung eksplisit
     * (decision-gate v0.26.3): WA japri dikirim ke SEMUA teknisi aktif
     * tenant terkait saat dispatch awal (siapa cepat dia dapat — assign
     * manual di /work-orders murni tracking, bukan penentu siapa dapat
     * notifikasi). Untuk reminder: kalau `technician_id` sudah terisi
     * (assign manual sudah terjadi), kirim CUMA ke teknisi itu; kalau
     * belum, broadcast lagi ke semua teknisi aktif — lihat
     * `WorkOrderDispatchService::notifyTechnicians()`.
     *
     * Kirim ke GRUP WA tetap placeholder (`Log::info()` TODO(v0.26.4)) —
     * `whatsapp-gateway/` (Go/whatsmeow) belum punya kapabilitas kirim ke
     * JID grup (`@g.us`) sama sekali, di luar scope v0.26.3.
     */
    case WorkOrderDispatched = 'work_order_dispatched';

    public function label(): string
    {
        return match ($this) {
            self::InvoiceDueReminder => 'Pengingat Jatuh Tempo Invoice',
            self::PaymentReceived => 'Pembayaran Diterima',
            self::CustomerRegistered => 'Pelanggan Terdaftar',
            self::CustomerSuspendedReminder => 'Pengingat Pelanggan Suspend',
            self::ReferrerActionOtp => 'Kode OTP Aksi Referrer',
            self::DuplicateSessionAttempt => 'Percobaan Pairing Sesi Duplikat',
            self::TechnicianFeaturePending => 'Fitur Teknisi Belum Tersedia',
            self::UnrecognizedMessageFallback => 'Fallback Pesan Tidak Dikenali',
            self::StaffInitialPasswordNotice => 'Password Awal Staff — Pengantar',
            self::StaffInitialPasswordValue => 'Password Awal Staff — Nilai Password',
            self::WorkOrderDispatched => 'Work Order Dispatch/Reminder Teknisi',
        };
    }
}
