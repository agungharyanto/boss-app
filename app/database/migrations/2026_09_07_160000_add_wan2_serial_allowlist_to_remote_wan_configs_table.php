<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SN allowlist untuk WAN2/bridge — lapisan keamanan tambahan fase testing
 * (keputusan Agung 2026-09-07). Guard WAN2 di `default-wan.js` sudah
 * di-redesign jadi berbasis ISI (cari bridge ConnectionType + VLAN cocok di
 * POSISI MANA PUN, bukan cuma slot 2) — tapi guard baru itu belum pernah
 * diverifikasi ke device asli, jadi selama testing WAN2 hanya menyentuh
 * device yang SN-nya ada di allowlist ini. Allowlist kosong = izinkan
 * semua (dilonggarkan setelah guard terbukti reliable).
 *
 * Migration terpisah (bukan edit `2026_09_07_150000`) — migration itu
 * sudah `migrate` di DB dev.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remote_wan_configs', function (Blueprint $table) {
            // Satu SN per baris (textarea di UI). Kosong = izinkan semua.
            $table->text('wan2_serial_allowlist')->nullable()->after('wan2_vlan');
        });
    }

    public function down(): void
    {
        Schema::table('remote_wan_configs', function (Blueprint $table) {
            $table->dropColumn('wan2_serial_allowlist');
        });
    }
};
