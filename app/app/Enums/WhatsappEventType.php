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

    /**
     * v0.13.4.1 amendment — reminder H+ untuk WO yang SUDAH diklaim via
     * signed-link (`work_orders.claimed_at` terisi) tapi belum selesai.
     * GENUINELY event type TERPISAH dari WorkOrderDispatched di atas —
     * bukan variabel `{status_notice}` beda seperti dispatch-vs-reminder
     * biasa — supaya admin/reseller bisa mengedit teks varian ini secara
     * independen lewat UI Template WA (keputusan Agung eksplisit saat
     * kickoff sub-versi ini, lihat WorkOrderDispatchService's own
     * docblock). Penerima: teknisi yang GENUINELY klaim WO ini
     * (`claimed_by_technician_id`), bukan broadcast ke semua teknisi
     * aktif — WO ini sudah bukan "up for grabs" lagi.
     *
     * TIDAK PERNAH membawa `{claim_link}` — link klaim sudah tidak
     * relevan begitu WO diklaim, dan link LANJUT AKTIVASI belum ada
     * (nyusul v0.13.4.2). TODO(v0.13.4.2): begitu link aktivasi ada,
     * tambahkan variabelnya ke sini supaya reminder ini kembali membawa
     * aksi konkret, bukan cuma info status.
     */
    case WorkOrderClaimedReminder = 'work_order_claimed_reminder';

    /**
     * v0.13.4.2.2 (disetujui Agung 2026-10-26) — dikirim ke teknisi PENGKLAIM
     * segera setelah klaim WO sukses (`WorkOrderClaimService::submit()`),
     * membawa `{activation_link}` (signed-link `work-orders.activate.show`,
     * unik per teknisi, berlaku 2 hari) untuk lanjut ke form AKTIVASI
     * pelanggan. Variabel: `{technician_name}` `{customer_name}`
     * `{work_order_id}` `{activation_link}`. Kegagalan kirim WA TIDAK pernah
     * menggagalkan klaim (hanya di-log). Satu pesan per klaim sukses.
     */
    case WorkOrderActivationReady = 'work_order_activation_ready';

    /**
     * v0.13.4.3.2 — dikirim ke GRUP WA teknisi saat Work Order berstatus
     * Completed: 1 pesan teks ringkasan lalu 6 foto berurutan (via pipeline
     * media v0.13.5.1). Variabel teks: `{technician_name}` `{customer_name}`
     * `{customer_cid}` `{work_order_id}` `{ssid}` `{service_type}` + baris
     * "Password WiFi: ****" LITERAL (password TIDAK PERNAH dikirim/di-log).
     * Best-effort: kegagalan WA tidak pernah memblokir penutupan WO
     * (dipicu lewat DB::afterCommit + try/catch di WorkOrderService::complete()).
     */
    case WorkOrderCompleted = 'work_order_completed';

    /**
     * v0.13.5.3 — "Tagihan terbit" + LAMPIRAN PDF invoice, dikirim ke
     * PELANGGAN. Pada sub-versi ini HANYA dipicu MANUAL oleh admin
     * (InvoicePdfSendService::sendManually) — kirim OTOMATIS saat invoice
     * terbit adalah v0.13.5.3.1 terpisah (belum dibangun; JANGAN tambah
     * sweep/command/hook sinkron di sini — burst ~308 invoice tengah malam
     * sekitar tgl 24/25, lihat CLAUDE.md).
     *
     * BEDA dari 4 event pelanggan lama: kirim manual SENGAJA MELEWATI saklar
     * `customer_auto_notify_enabled` (saklar itu hanya untuk kiriman
     * OTOMATIS). Bypass ditegakkan lewat `InvoiceSendContext` yang HANYA
     * dibangun `InvoicePdfSendService` — semua pemanggil otomatis tetap
     * ber-guard (lihat `WhatsappGatewayService::buildAndQueue()` + test
     * arsitektur). Variabel teks: `{customer_name}` `{invoice_number}`
     * `{package_name}` `{total_amount}` `{due_date}` `{period}`
     * `{cs_number}` `{company_name}` (TANPA `{payment_link}` — Xendit
     * mati/sandbox; kalau variabelnya ada, dirender kosong tanpa memblokir).
     */
    case InvoiceIssued = 'invoice_issued';

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
            self::WorkOrderClaimedReminder => 'Work Order Reminder (Sudah Diklaim)',
            self::WorkOrderActivationReady => 'Work Order Siap Aktivasi (Link Aktivasi)',
            self::WorkOrderCompleted => 'Work Order Selesai (Grup + Foto)',
            self::InvoiceIssued => 'Tagihan Terbit (PDF ke Pelanggan)',
        };
    }
}
