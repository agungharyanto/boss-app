<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.5.1.2 — saklar per-tenant "Notifikasi Otomatis ke Pelanggan".
 * Aditif: kolom boolean NOT NULL default TRUE (pertahankan perilaku saat ini
 * untuk semua baris yang sudah ada). Tenant 1 di-set OFF lewat langkah rilis
 * eksplisit (UI/tinker), BUKAN data migration — supaya migrasi ini generik &
 * aman di lingkungan mana pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_dispatch_settings', function (Blueprint $table) {
            $table->boolean('customer_auto_notify_enabled')->default(true)->after('command_interval_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_dispatch_settings', function (Blueprint $table) {
            $table->dropColumn('customer_auto_notify_enabled');
        });
    }
};
