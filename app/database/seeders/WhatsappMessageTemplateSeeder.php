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
