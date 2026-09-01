<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.16.0 Core Network Infrastructure Management, Langkah 7. Menandai
 * bahwa sebuah core (yang di-patch ke port OTB) sebenarnya tersambung
 * LANGSUNG ke sebuah perangkat aktif OLT, bukan ke node/kabel distribusi
 * berikutnya.
 *
 * `olt_device_id` — FK ke `olt_devices` (registry OLT first-class dari
 * v0.8.1). `nullOnDelete` biar hapus OLT tidak menghapus core-nya, cuma
 * melepas tautan.
 *
 * `olt_pon_port_label` — string bebas ("PON 1", "GPON 0/1/3"). PON port
 * BUKAN entity terstruktur di codebase ini — cuma label fisik di chassis.
 *
 * Keduanya NULLABLE & opsional: mayoritas port OTB "belum dipatch" atau
 * ke node distribusi biasa — itu tetap valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiber_cores', function (Blueprint $table) {
            $table->foreignId('olt_device_id')->nullable()->constrained('olt_devices')->nullOnDelete();
            $table->string('olt_pon_port_label')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fiber_cores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('olt_device_id');
            $table->dropColumn('olt_pon_port_label');
        });
    }
};
