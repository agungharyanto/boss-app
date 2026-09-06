<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.9.0 (sisa scope Commission — Approval + Clawback).
 *
 * - `reversal_of_id` — self-FK. Sebuah baris "Clawback" (pembatalan komisi,
 *   `CommissionStatus::Clawback`, `amount` negatif) menunjuk ke baris asli
 *   yang di-reverse. Baris asli TIDAK PERNAH diubah/dihapus (append-only,
 *   sama prinsip yang dipegang sepanjang proyek: `reseller_tax_ledger`,
 *   `cpe_action_logs`, dst).
 * - `reviewed_by` / `reviewed_at` — jejak admin yang meng-Approve/Reject
 *   (Eligible→Approved / Eligible→Rejected) sebuah baris komisi bulanan,
 *   ATAU yang membuat baris Clawback. Pola sama `deposited_by`/`deposited_at`
 *   (v0.9.6) dan `paid_by`/`paid_at` (v0.9.11).
 * - Alasan Reject / alasan Clawback ditulis ke kolom `notes` yang sudah ada
 *   (nullable text), di-append dengan prefix jejak — tidak bikin kolom baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->foreignId('reversal_of_id')
                ->nullable()
                ->after('invoice_id')
                ->constrained('commission_ledger')
                ->nullOnDelete();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->after('paid_by')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_of_id');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn('reviewed_at');
        });
    }
};
