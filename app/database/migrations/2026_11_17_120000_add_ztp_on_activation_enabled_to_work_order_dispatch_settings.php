<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.4.2.3 — saklar per-tenant "Pemicu OMCI/ZTP saat Aktivasi".
 * Aditif: kolom boolean NOT NULL default FALSE (pertahankan perilaku aman untuk
 * semua baris yang sudah ada — aktivasi TIDAK men-dispatch ProvisionOnuViaZtp
 * selama saklar ini OFF). Dinyalakan lewat UI/langkah rilis eksplisit, BUKAN
 * data migration. Saklar ini lapisan di ATAS gate kode keras
 * OLT_ZTP_OMCI_ACTIVATION_ENABLED (env) yang masih menjaga jalur WAN OMCI/ZTE
 * di dalam job — lihat WorkOrderActivationService + CLAUDE.md v0.13.4.2.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_dispatch_settings', function (Blueprint $table) {
            $table->boolean('ztp_on_activation_enabled')->default(false)->after('invoice_pdf_auto_send_daily_cap');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_dispatch_settings', function (Blueprint $table) {
            $table->dropColumn('ztp_on_activation_enabled');
        });
    }
};
