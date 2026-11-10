<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.5.3.1 — "Kirim PDF Invoice Otomatis" per tenant (saklar KEDUA, harus
 * lolos BERSAMA `customer_auto_notify_enabled`). Semua aditif, nol efek ke
 * baris lama (default FALSE = fitur mati untuk semua tenant sampai dinyalakan).
 *
 *  - invoice_pdf_auto_send_enabled: master switch (default FALSE).
 *  - invoice_pdf_auto_send_enabled_at: diisi saat transisi OFF->ON; jadi batas
 *    bawah "hanya invoice yang terbit SETELAH saklar dinyalakan" bila
 *    `invoice_pdf_auto_send_from` kosong.
 *  - invoice_pdf_auto_send_from: override batas bawah manual (date) — "Mulai
 *    dari tanggal". Bila diisi, menang atas enabled_at.
 *  - invoice_pdf_auto_send_daily_cap: cap anti-ban per hari per sesi tujuan
 *    (default 80; UI membatasi 1..200).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_dispatch_settings', function (Blueprint $table) {
            $table->boolean('invoice_pdf_auto_send_enabled')->default(false)->after('invoice_pdf_test_recipient');
            $table->timestamp('invoice_pdf_auto_send_enabled_at')->nullable()->after('invoice_pdf_auto_send_enabled');
            $table->date('invoice_pdf_auto_send_from')->nullable()->after('invoice_pdf_auto_send_enabled_at');
            $table->unsignedSmallInteger('invoice_pdf_auto_send_daily_cap')->default(80)->after('invoice_pdf_auto_send_from');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_dispatch_settings', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_pdf_auto_send_enabled',
                'invoice_pdf_auto_send_enabled_at',
                'invoice_pdf_auto_send_from',
                'invoice_pdf_auto_send_daily_cap',
            ]);
        });
    }
};
