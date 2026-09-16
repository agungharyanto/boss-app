<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.26.2 — dikonfirmasi lewat investigasi Langkah 0: kedua kolom ini
 * belum ada sama sekali di skema/model sebelum migration ini (tidak
 * bentrok).
 *
 * `dispatched_at` — diisi SEKALI, saat WO pertama kali "keluar" (baik
 * langsung untuk WO tanpa janji spesifik, atau saat window
 * scheduled_at-offset tercapai untuk WO dengan janji). null selamanya
 * berarti belum pernah dispatch. TIDAK ada logic yang pernah
 * mengosongkannya kembali.
 *
 * `last_reminder_sent_at` — DATE (bukan timestamp) SENGAJA — reminder
 * cuma perlu presisi "hari ini vs bukan", supaya perbandingan "apakah
 * WO ini sudah dapat reminder hari ini" jadi perbandingan tanggal
 * langsung (`whereDate`/`->toDateString()`), bukan perlu hitung window
 * jam segala.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->timestamp('dispatched_at')->nullable()->after('technician_confirmed_at');
            $table->date('last_reminder_sent_at')->nullable()->after('dispatched_at');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['dispatched_at', 'last_reminder_sent_at']);
        });
    }
};
