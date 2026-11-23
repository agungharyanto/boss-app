<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.28.2 (T2) — pengaturan CustomerAutoSendGate per tenant.
 *
 * - RENAME `invoice_pdf_auto_send_daily_cap` → `customer_auto_send_daily_cap`
 *   (nilai lama dipertahankan): plafon kini GABUNGAN semua jalur otomatis
 *   (K2), bukan khusus sweep invoice.
 * - `customer_auto_send_window_start`/`_end` (string 'HH:MM', default 08:00/
 *   20:00): jendela jam per tenant untuk keempat jalur (K3).
 * - `customer_auto_send_breaker_threshold` (default 10): N kegagalan otomatis
 *   beruntun → pemutus terbuka (K12).
 *
 * Semua default TIDAK menyalakan pengiriman apa pun (saklar induk tetap OFF).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_dispatch_settings', function (Blueprint $table) {
            $table->renameColumn('invoice_pdf_auto_send_daily_cap', 'customer_auto_send_daily_cap');
        });

        Schema::table('work_order_dispatch_settings', function (Blueprint $table) {
            $table->string('customer_auto_send_window_start', 5)->default('08:00')->after('customer_auto_send_daily_cap');
            $table->string('customer_auto_send_window_end', 5)->default('20:00')->after('customer_auto_send_window_start');
            $table->unsignedSmallInteger('customer_auto_send_breaker_threshold')->default(10)->after('customer_auto_send_window_end');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_dispatch_settings', function (Blueprint $table) {
            $table->dropColumn(['customer_auto_send_window_start', 'customer_auto_send_window_end', 'customer_auto_send_breaker_threshold']);
        });

        Schema::table('work_order_dispatch_settings', function (Blueprint $table) {
            $table->renameColumn('customer_auto_send_daily_cap', 'invoice_pdf_auto_send_daily_cap');
        });
    }
};
