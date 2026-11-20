<?php

namespace Database\Seeders;

use App\Enums\WhatsappEventType;
use App\Models\Tenant;
use App\Models\WhatsappMessageTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeds one default ISP-level template (reseller_id null) per event_type,
 * for every existing tenant — this is what makes
 * WhatsappTemplateService::resolve() reliably non-null for a tenant that
 * hasn't customized anything yet. firstOrCreate — safe to re-run, never
 * overwrites a template an admin has already edited.
 */
class WhatsappMessageTemplateSeeder extends Seeder
{
    /**
     * v0.13.5.4 — body bawaan invoice_issued SEBELUM {payment_instructions}
     * ditambahkan. Template tenant yang isinya PERSIS ini (belum pernah diedit
     * admin) diperbarui ke bawaan baru; selain itu dibiarkan (dilaporkan).
     */
    private const LEGACY_INVOICE_ISSUED = 'Halo {customer_name}, tagihan internet Anda sudah terbit.'.PHP_EOL.'Invoice: {invoice_number}'.PHP_EOL.'Paket: {package_name}'.PHP_EOL.'Total: {total_amount}'.PHP_EOL.'Jatuh tempo: {due_date}'.PHP_EOL.'Rincian lengkap ada pada file PDF terlampir.'.PHP_EOL.'Bantuan: CS {cs_number}'.PHP_EOL.'Terima kasih - {company_name}';

    /**
     * v0.13.5.5 (T3) — body bawaan payment_received SEBELUM ditambahkan
     * {paid_date} + menyebut lampiran kuitansi. Template tenant yang isinya
     * PERSIS ini (belum diedit admin) diperbarui ke bawaan baru; selain itu
     * dibiarkan. Sejak T3, event ini TIDAK lagi dikirim otomatis oleh markPaid()
     * — hanya dikirim MANUAL sebagai caption kuitansi (InvoicePdfSendService).
     */
    private const LEGACY_PAYMENT_RECEIVED = 'Halo {customer_name}, pembayaran invoice {invoice_number} sebesar {total_amount} telah kami terima. Terima kasih atas kepercayaan Anda — {company_name}.';

    public function run(): void
    {
        $defaults = [
            WhatsappEventType::InvoiceDueReminder->value => 'Halo {customer_name}, invoice {invoice_number} sebesar {total_amount} untuk paket {package_name} akan jatuh tempo pada {due_date}. Silakan lakukan pembayaran melalui: {payment_link}. Terima kasih — {company_name}.',
            // v0.13.5.5 (T3) — caption kuitansi MANUAL: tambah {paid_date} +
            // sebut lampiran kuitansi PDF. Tidak lagi dikirim otomatis.
            WhatsappEventType::PaymentReceived->value => 'Halo {customer_name}, pembayaran invoice {invoice_number} sebesar {total_amount} telah kami terima pada {paid_date}. Kuitansi pembayaran terlampir. Terima kasih atas kepercayaan Anda — {company_name}.',
            WhatsappEventType::CustomerRegistered->value => 'Halo {customer_name}, terima kasih telah mendaftar layanan {package_name} di {company_name}. Tim kami akan segera menghubungi Anda untuk proses instalasi.',
            WhatsappEventType::CustomerSuspendedReminder->value => 'Halo {customer_name}, layanan Anda saat ini berstatus suspend. Segera lakukan pembayaran tertunggak agar layanan dapat diaktifkan kembali. Hubungi {company_name} untuk bantuan.',
            WhatsappEventType::ReferrerActionOtp->value => 'Halo {referrer_name}, kode verifikasi Anda: *{otp_code}*. Digunakan untuk: {action_label}. Berlaku {otp_minutes} menit. JANGAN bagikan kode ini ke siapa pun — {company_name}.',
            WhatsappEventType::DuplicateSessionAttempt->value => 'Nomor WhatsApp Anda baru saja dicoba dipasang ulang di BOSS App pada akun {reseller_name} ({attempted_at}). Kalau ini bukan Anda, harap segera hubungi admin ISP Anda — {company_name}.',
            WhatsappEventType::TechnicianFeaturePending->value => 'Halo {technician_name}, terima kasih sudah menghubungi kami. Fitur untuk membantu proses pekerjaan Anda lewat WhatsApp masih dalam pengembangan — mohon lanjutkan lewat aplikasi/prosedur yang biasa dipakai untuk saat ini — {company_name}.',
            WhatsappEventType::UnrecognizedMessageFallback->value => 'Maaf, pesan Anda tidak dapat kami proses secara otomatis. Silakan hubungi Customer Service kami untuk bantuan lebih lanjut — {company_name}.',
            // v0.22.4 — StaffInitialPasswordValue SENGAJA TIDAK di-seed di
            // sini (dan tidak akan pernah) — pesan itu tidak pernah melalui
            // WhatsappTemplateService sama sekali, lihat docblock enum-nya.
            WhatsappEventType::StaffInitialPasswordNotice->value => 'Halo {recipient_name}, akun staff BOSS App Anda sudah dibuat. Password login akan dikirim di pesan berikutnya — {company_name}.',
            // v0.26.3 — {status_notice} beda isinya tergantung dispatch awal
            // vs reminder (lihat docblock WhatsappEventType::WorkOrderDispatched
            // dan WorkOrderDispatchService::notifyTechnicians()), bukan 2
            // template terpisah — resolve() cuma per event_type.
            WhatsappEventType::WorkOrderDispatched->value => 'Halo {technician_name}, {status_notice}'.PHP_EOL.'WO #{work_order_id} — {customer_name}'.PHP_EOL.'Alamat: {customer_address}'.PHP_EOL.'Layanan: {service_type}'.PHP_EOL.'Janji Kunjungan: {scheduled_at}'.PHP_EOL.'— {company_name}.',
            // v0.13.4.1 amendment — event type TERPISAH dari
            // WorkOrderDispatched, lihat docblock enum-nya. Sengaja tidak
            // menyebut {claim_link} sama sekali — link klaim sudah tidak
            // relevan untuk WO yang sudah diklaim.
            WhatsappEventType::WorkOrderClaimedReminder->value => 'Halo {technician_name}, REMINDER — WO #{work_order_id} yang sudah Anda klaim ({claimed_by_name}) untuk {customer_name} belum selesai, mohon segera ditindaklanjuti.'.PHP_EOL.'Alamat: {customer_address}'.PHP_EOL.'Layanan: {service_type}'.PHP_EOL.'Diklaim sejak: {claimed_at}'.PHP_EOL.'— {company_name}.',

            // v0.13.4.2.2 — dikirim ke teknisi pengklaim setelah klaim sukses,
            // membawa link lanjut ke form AKTIVASI pelanggan. HANYA 4 variabel
            // (lihat docblock WhatsappEventType::WorkOrderActivationReady).
            WhatsappEventType::WorkOrderActivationReady->value => 'Halo {technician_name}, WO #{work_order_id} untuk {customer_name} siap DIAKTIVASI.'.PHP_EOL.'Buka link berikut untuk mengaktifkan layanan pelanggan:'.PHP_EOL.'{activation_link}'.PHP_EOL.PHP_EOL.'Setelah instalasi & uji coba selesai, tutup Work Order lewat link berikut:'.PHP_EOL.'{close_link}',

            // v0.13.4.3.2 — pesan RINGKASAN ke grup WA teknisi saat WO selesai,
            // diikuti 6 foto berurutan (pesan terpisah, lihat
            // WorkOrderDispatchService::notifyWorkOrderCompleted). Baris
            // "Password WiFi: ****" LITERAL — password tidak pernah dikirim.
            WhatsappEventType::WorkOrderCompleted->value => 'Work Order #{work_order_id} SELESAI.'.PHP_EOL.'Teknisi: {technician_name}'.PHP_EOL.'Pelanggan: {customer_name} (CID {customer_cid})'.PHP_EOL.'Layanan: {service_type}'.PHP_EOL.'SSID WiFi: {ssid}'.PHP_EOL.'Password WiFi: ****'.PHP_EOL.'Foto dokumentasi menyusul di bawah.',

            // v0.13.5.3 — "Tagihan terbit" + lampiran PDF, dikirim MANUAL ke
            // pelanggan. TANPA {payment_link} (Xendit mati/sandbox). Caption =
            // teks ini; PDF menyusul sebagai lampiran pada pesan yang sama.
            // v0.13.5.4 — tambah baris {payment_instructions} (cara bayar ringkas).
            WhatsappEventType::InvoiceIssued->value => 'Halo {customer_name}, tagihan internet Anda sudah terbit.'.PHP_EOL.'Invoice: {invoice_number}'.PHP_EOL.'Paket: {package_name}'.PHP_EOL.'Total: {total_amount}'.PHP_EOL.'Jatuh tempo: {due_date}'.PHP_EOL.'Cara bayar: {payment_instructions}'.PHP_EOL.'Rincian lengkap ada pada file PDF terlampir.'.PHP_EOL.'Bantuan: CS {cs_number}'.PHP_EOL.'Terima kasih - {company_name}',

            // v0.13.4.2.3 — pemicu OMCI/ZTP GAGAL saat aktivasi, ke GRUP/admin
            // (bukan pelanggan). Tanpa data sensitif berlebih.
            WhatsappEventType::OnuProvisioningFailed->value => '⚠️ Provisioning OLT/ONU GAGAL (perlu cek NOC).'.PHP_EOL.'WO #{work_order_id} — {customer_name} (CID {cid})'.PHP_EOL.'ONU SN: {onu_sn}'.PHP_EOL.'OLT: {olt_name}'.PHP_EOL.'Tahap: {stage}'.PHP_EOL.'Alasan: {reason}'.PHP_EOL.'Status aktivasi: {status_link}',
        ];

        Tenant::all()->each(function (Tenant $tenant) use ($defaults) {
            foreach ($defaults as $eventType => $content) {
                WhatsappMessageTemplate::withoutGlobalScopes()->firstOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'reseller_id' => null,
                        'event_type' => $eventType,
                    ],
                    [
                        'content' => $content,
                        'is_active' => true,
                    ]
                );
            }
        });

        // v0.13.5.4 — template invoice_issued tenant lama diperbarui HANYA bila
        // isinya PERSIS bawaan lama (belum diedit admin). Yang sudah diedit
        // dibiarkan utuh dan dilaporkan jumlahnya.
        $newInvoiceIssued = $defaults[WhatsappEventType::InvoiceIssued->value];
        $updated = 0;
        $leftEdited = 0;
        WhatsappMessageTemplate::withoutGlobalScopes()
            ->where('event_type', WhatsappEventType::InvoiceIssued->value)
            ->whereNull('reseller_id')
            ->get()
            ->each(function (WhatsappMessageTemplate $tpl) use ($newInvoiceIssued, &$updated, &$leftEdited) {
                if ($tpl->content === self::LEGACY_INVOICE_ISSUED) {
                    $tpl->update(['content' => $newInvoiceIssued]);
                    $updated++;
                } elseif ($tpl->content !== $newInvoiceIssued) {
                    $leftEdited++;
                }
            });

        $this->command?->info("invoice_issued: {$updated} template diperbarui ke bawaan baru, {$leftEdited} dibiarkan (sudah diedit admin).");

        // v0.13.5.5 (T3) — payment_received: pola sama, HANYA update yang isinya
        // PERSIS bawaan lama (belum diedit admin), supaya {paid_date} + sebutan
        // kuitansi masuk. Yang sudah diedit dibiarkan utuh.
        $newPaymentReceived = $defaults[WhatsappEventType::PaymentReceived->value];
        $prUpdated = 0;
        $prLeftEdited = 0;
        WhatsappMessageTemplate::withoutGlobalScopes()
            ->where('event_type', WhatsappEventType::PaymentReceived->value)
            ->whereNull('reseller_id')
            ->get()
            ->each(function (WhatsappMessageTemplate $tpl) use ($newPaymentReceived, &$prUpdated, &$prLeftEdited) {
                if ($tpl->content === self::LEGACY_PAYMENT_RECEIVED) {
                    $tpl->update(['content' => $newPaymentReceived]);
                    $prUpdated++;
                } elseif ($tpl->content !== $newPaymentReceived) {
                    $prLeftEdited++;
                }
            });

        $this->command?->info("payment_received: {$prUpdated} template diperbarui ke bawaan baru, {$prLeftEdited} dibiarkan (sudah diedit admin).");
    }
}
