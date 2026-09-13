<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.12.7 Langkah 3 — flag "teknisi sudah konfirmasi instalasi via OTP
 * WhatsApp" (App\Services\Installation\TechnicianActionOtpService, siap
 * sejak v0.12.1, baru genuinely dipakai di sini). Timestamp nullable
 * (bukan boolean) — konsisten dengan completed_at/scheduled_at yang sudah
 * ada di tabel ini, juga menyimpan KAPAN dikonfirmasi, bukan cuma
 * sudah/belum. WorkOrderService::complete() menolak transisi ke Completed
 * selama kolom ini masih null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->timestamp('technician_confirmed_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn('technician_confirmed_at');
        });
    }
};
