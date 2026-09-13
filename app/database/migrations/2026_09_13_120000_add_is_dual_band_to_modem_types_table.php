<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.12.5 — kapabilitas WiFi band Tipe Modem, diisi manual admin di modal
 * "Kelola Tipe Modem". SENGAJA belum di-wire ke fitur SSID PSB apa pun di
 * sesi ini — data pendukung saja, dipakai v0.12.7 Bagian C (input SSID
 * teknisi saat instalasi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modem_types', function (Blueprint $table) {
            $table->boolean('is_dual_band')->default(false)->after('manufacturer_match_patterns');
        });
    }

    public function down(): void
    {
        Schema::table('modem_types', function (Blueprint $table) {
            $table->dropColumn('is_dual_band');
        });
    }
};
