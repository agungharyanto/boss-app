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
    public function run(): void
    {
        $defaults = [
            WhatsappEventType::InvoiceDueReminder->value => 'Halo {customer_name}, invoice {invoice_number} sebesar {total_amount} untuk paket {package_name} akan jatuh tempo pada {due_date}. Silakan lakukan pembayaran melalui: {payment_link}. Terima kasih — {company_name}.',
            WhatsappEventType::PaymentReceived->value => 'Halo {customer_name}, pembayaran invoice {invoice_number} sebesar {total_amount} telah kami terima. Terima kasih atas kepercayaan Anda — {company_name}.',
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
    }
}
