<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.16.0 Core Network Infrastructure Management, Langkah 6. Port fisik
 * OTB tempat sebuah core diterminasi/di-patch.
 *
 * NULLABLE — tidak semua core langsung dipakai; core yang belum
 * di-patch tidak menempati port apa pun. "port_number <= port_count OTB"
 * dan "unik per OTB" ditegakkan di layer aplikasi (FiberTopologyService/
 * Livewire), bukan constraint DB — port_number di sini menunjuk port
 * pada OTB yang menjadi from_type/from_id kabel core ini, sesuatu yang
 * tidak bisa diwakili FK atau unique index tunggal di tabel fiber_cores.
 *
 * Migration BARU, bukan edit ke create_fiber_cores_table (Langkah 1) —
 * sudah dijalankan di server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiber_cores', function (Blueprint $table) {
            $table->unsignedInteger('port_number')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fiber_cores', function (Blueprint $table) {
            $table->dropColumn('port_number');
        });
    }
};
