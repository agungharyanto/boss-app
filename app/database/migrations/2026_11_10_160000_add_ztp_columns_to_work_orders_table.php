<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.4.2.3 — pemicu OMCI/ZTP saat aktivasi. Kolom aditif pada `work_orders`
 * untuk melacak hasil provisioning OLT yang dipicu dari aktivasi signed-link.
 * Semua nullable/ber-default — WO lama tak terpengaruh. `ztp_last_result`
 * menyimpan hasil per-tahap `ZtpOrchestratorService::provision()` apa adanya
 * (bukan data sensitif OLT — lihat Job yang menulisnya). `ztp_olt_device_id`
 * = OLT tempat ONU ditemukan OnuDiscoveryService (bukti jejak, nullable FK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('ztp_status')->nullable()->after('technician_confirmed_at');
            $table->unsignedSmallInteger('ztp_attempts')->default(0)->after('ztp_status');
            $table->timestamp('ztp_provisioned_at')->nullable()->after('ztp_attempts');
            $table->json('ztp_last_result')->nullable()->after('ztp_provisioned_at');
            $table->foreignId('ztp_olt_device_id')->nullable()->after('ztp_last_result')->constrained('olt_devices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ztp_olt_device_id');
            $table->dropColumn(['ztp_status', 'ztp_attempts', 'ztp_provisioned_at', 'ztp_last_result']);
        });
    }
};
