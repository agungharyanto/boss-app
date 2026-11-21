<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.28.1 (T1) — opt-out notifikasi WA OTOMATIS per-pelanggan.
 *
 * Lapisan DI ATAS saklar per-tenant `customer_auto_notify_enabled`
 * (v0.13.5.1.2): pelanggan yang opt-out TIDAK menerima notifikasi OTOMATIS apa
 * pun (invoice_issued/invoice_due_reminder/customer_suspended_reminder/
 * customer_registered). Penegakan di `WhatsappGatewayService::buildAndQueue()`
 * (jalur `context === null`). Kirim MANUAL (`InvoiceSendContext`) TIDAK
 * terpengaruh.
 *
 * `default false` (NOT NULL) → tidak mengubah perilaku pelanggan existing;
 * RILIS fitur ini TIDAK meng-opt-out siapa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('auto_notify_opt_out')->default(false)->after('tax_billable');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('auto_notify_opt_out');
        });
    }
};
