<?php

namespace Database\Seeders;

use App\Enums\PaymentGatewayChannelCategory;
use App\Models\PaymentGatewayChannel;
use Illuminate\Database\Seeder;

/**
 * Populates the Xendit channel catalog (v0.3.5 Fase H) — every channel a
 * BOSS App Xendit account can plausibly offer, all disabled by default
 * (admin turns individual channels on from Pengaturan > Payment Gateway).
 *
 * Note (scope boundary, see CLAUDE.md/CHANGELOG): only bank_transfer_va,
 * qris, and invoice categories actually have a working
 * XenditGatewayService/PaymentService integration this sprint (the original
 * v0.3.5 architecture decision was VA+QRIS+Invoice only). ewallet,
 * retail_outlet, and credit_card channels are seeded here so they exist in
 * the catalog/checklist UI (matching the MikRadius-style reference), but
 * PaymentService::createPaymentFor() deliberately rejects them with a clear
 * "not yet supported" error until their own Xendit API integration is
 * built — enabling them in the checklist does not make them usable yet.
 */
class PaymentGatewayChannelSeeder extends Seeder
{
    public function run(): void
    {
        $channels = [
            ['code' => 'BRI_VA', 'label' => 'BRI Virtual Account', 'category' => PaymentGatewayChannelCategory::BankTransferVa],
            ['code' => 'BSI_VA', 'label' => 'BSI Virtual Account', 'category' => PaymentGatewayChannelCategory::BankTransferVa],
            ['code' => 'BNI_VA', 'label' => 'BNI Virtual Account', 'category' => PaymentGatewayChannelCategory::BankTransferVa],
            ['code' => 'MANDIRI_VA', 'label' => 'Mandiri Virtual Account', 'category' => PaymentGatewayChannelCategory::BankTransferVa],
            ['code' => 'CIMB_VA', 'label' => 'CIMB Niaga Virtual Account', 'category' => PaymentGatewayChannelCategory::BankTransferVa],
            ['code' => 'BCA_VA', 'label' => 'BCA Virtual Account', 'category' => PaymentGatewayChannelCategory::BankTransferVa],
            ['code' => 'PERMATA_VA', 'label' => 'Permata Virtual Account', 'category' => PaymentGatewayChannelCategory::BankTransferVa],
            ['code' => 'BJB_VA', 'label' => 'BJB Virtual Account', 'category' => PaymentGatewayChannelCategory::BankTransferVa],
            ['code' => 'SAHABAT_SAMPOERNA_VA', 'label' => 'Sahabat Sampoerna Virtual Account', 'category' => PaymentGatewayChannelCategory::BankTransferVa],
            ['code' => 'CREDIT_CARD', 'label' => 'Credit Card', 'category' => PaymentGatewayChannelCategory::CreditCard],
            ['code' => 'ALFAMART', 'label' => 'Alfamart', 'category' => PaymentGatewayChannelCategory::RetailOutlet],
            ['code' => 'INDOMARET', 'label' => 'Indomaret', 'category' => PaymentGatewayChannelCategory::RetailOutlet],
            ['code' => 'OVO', 'label' => 'OVO', 'category' => PaymentGatewayChannelCategory::Ewallet],
            ['code' => 'LINKAJA', 'label' => 'LinkAja', 'category' => PaymentGatewayChannelCategory::Ewallet],
            ['code' => 'DANA', 'label' => 'DANA', 'category' => PaymentGatewayChannelCategory::Ewallet],
            ['code' => 'SHOPEEPAY', 'label' => 'ShopeePay', 'category' => PaymentGatewayChannelCategory::Ewallet],
            ['code' => 'QRIS', 'label' => 'QRIS', 'category' => PaymentGatewayChannelCategory::Qris],
            ['code' => 'XENDIT_INVOICE', 'label' => 'Xendit Invoice', 'category' => PaymentGatewayChannelCategory::Invoice],
        ];

        foreach ($channels as $channel) {
            PaymentGatewayChannel::query()->firstOrCreate(
                ['code' => $channel['code']],
                ['label' => $channel['label'], 'category' => $channel['category'], 'enabled' => false]
            );
        }
    }
}
