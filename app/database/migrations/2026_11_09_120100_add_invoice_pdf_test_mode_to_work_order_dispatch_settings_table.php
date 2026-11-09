<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.5.3 — "Mode Uji Kirim Invoice" per tenant. Bila AKTIF, kirim PDF
 * invoice (manual) dialihkan ke invoice_pdf_test_recipient — pelanggan nyata
 * TIDAK menerima apa pun. Default MATI (FALSE) semua tenant.
 *
 * invoice_pdf_test_recipient dinormalisasi ke 62... di service/UI sebelum
 * disimpan; kolom hanya menampung nilai mentah string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_dispatch_settings', function (Blueprint $table) {
            $table->boolean('invoice_pdf_test_mode_enabled')->default(false)
                ->after('customer_auto_notify_enabled');
            $table->string('invoice_pdf_test_recipient')->nullable()
                ->after('invoice_pdf_test_mode_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_dispatch_settings', function (Blueprint $table) {
            $table->dropColumn(['invoice_pdf_test_mode_enabled', 'invoice_pdf_test_recipient']);
        });
    }
};
