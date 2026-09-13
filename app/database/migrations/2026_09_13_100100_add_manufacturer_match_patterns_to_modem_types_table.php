<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.12.5 (revisi arsitektur) — dasar auto-suggest Template Konfig CPE.
 * Comma-separated, dicocokkan (setelah normalisasi kapital/spasi) ke
 * `cpe_devices.manufacturer` (kode OUI mentah GenieACS, mis. "ZICG",
 * "CIOT" — lihat investigasi v0.12.4 untuk kenapa field ini yang
 * dipakai, bukan `model_name` yang 92,5% kosong). Diisi MANUAL admin
 * kapan pun tahu kode OUI suatu Tipe Modem — bukan hasil deteksi
 * otomatis, sama filosofi dengan Tipe Modem itu sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modem_types', function (Blueprint $table) {
            $table->text('manufacturer_match_patterns')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('modem_types', function (Blueprint $table) {
            $table->dropColumn('manufacturer_match_patterns');
        });
    }
};
