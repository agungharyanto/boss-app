<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.12.4 — titik input teknisi untuk Tipe Modem. Kolom disiapkan di
 * sub-versi ini; wiring penuh ke form scan device teknisi (Bagian C) baru
 * di v0.12.7 — lihat `modem_types` migration untuk konteks lengkap kenapa
 * field ini manual, bukan hasil deteksi TR-069.
 *
 * `nullOnDelete()` (beda dari `wan_config_templates.modem_type_id` yang
 * `restrictOnDelete()`) — baris ini catatan HISTORIS instalasi (kapan
 * device di-scan, modem apa yang dipasang), bukan konfigurasi live yang
 * harus diblokir kalau ModemType-nya dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_devices', function (Blueprint $table) {
            $table->foreignId('modem_type_id')
                ->nullable()
                ->after('device_type')
                ->constrained('modem_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_order_devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('modem_type_id');
        });
    }
};
