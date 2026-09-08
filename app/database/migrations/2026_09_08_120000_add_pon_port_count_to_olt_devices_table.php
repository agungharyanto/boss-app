<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.16.1 Revisi F — jumlah PON port fisik sebuah OLT device, diisi
 * MANUAL oleh admin (Agung paling tahu tiap chassis) — BUKAN dari
 * discovery SNMP/LibreNMS (tidak ada data terstruktur, sudah dikonfirmasi).
 * NULLABLE: device lama yang belum diisi tetap jalan seperti sekarang;
 * di form assign-port, kalau terisi maka `olt_pon_port_label` jadi
 * dropdown "PON 1".."PON N", kalau null tetap teks bebas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olt_devices', function (Blueprint $table) {
            $table->unsignedInteger('pon_port_count')->nullable()->after('snmp_rw_community');
        });
    }

    public function down(): void
    {
        Schema::table('olt_devices', function (Blueprint $table) {
            $table->dropColumn('pon_port_count');
        });
    }
};
