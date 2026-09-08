<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SN allowlist untuk WAN1 juga (simetris dengan wan2_serial_allowlist).
 *
 * Redesign 2026-09-07: provision `default-wan` TIDAK lagi di-fold ke preset
 * `default` (fleet-wide, ~414 device — sudah ada masalah kronis
 * `too_many_commits`/`too_many_rpcs` di ~68 device pohon-besar, menambah
 * beban tiap Inform berisiko memperburuk). Sekarang preset TERPISAH
 * `boss-auto-wan` dengan `precondition` yang men-SCOPE eksekusi ke daftar
 * SN (union wan1 + wan2 allowlist). Kedua allowlist kosong + enabled =
 * precondition "true" (fleet-wide — state akhir "dilonggarkan", disengaja).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remote_wan_configs', function (Blueprint $table) {
            $table->text('wan1_serial_allowlist')->nullable()->after('wan1_pppoe_password');
        });
    }

    public function down(): void
    {
        Schema::table('remote_wan_configs', function (Blueprint $table) {
            $table->dropColumn('wan1_serial_allowlist');
        });
    }
};
