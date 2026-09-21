<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.4.1 amendment — murni metadata tracking, TIDAK menyentuh
 * App\Enums\WorkOrderStatus sama sekali (keputusan yang sudah dikunci: klaim
 * via signed-link tidak pernah mengubah status WO). Diisi
 * WorkOrderClaimService::submit() di klaim PERTAMA yang berhasil, dipakai
 * WorkOrderClaimController untuk membedakan "belum diklaim" (tampilkan form)
 * vs "sudah diklaim" (tampilkan read-only), dan
 * WorkOrderDispatchService::runReminderCycle() untuk memilih varian pesan
 * reminder yang tidak lagi menyertakan claim_link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->timestamp('claimed_at')->nullable()->after('technician_confirmed_at');
            $table->foreignId('claimed_by_technician_id')->nullable()->after('claimed_at')
                ->constrained('technicians')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('claimed_by_technician_id');
            $table->dropColumn('claimed_at');
        });
    }
};
