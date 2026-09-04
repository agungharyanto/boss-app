<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint "perpanjang-daftar-pelanggan" (tracking setoran titip) —
 * `commission_ledger` sebelumnya hanya menyimpan nominal KOMISI (`amount`),
 * bukan TOTAL uang yang dipegang Referrer dari pelanggan saat Titip. Empat
 * kolom baru, semua khusus relevan untuk `scheme = titip` (NULL untuk skema
 * lain):
 *
 * - `gross_amount`   — total uang diterima dari pelanggan, diambil dari
 *   `sell_price` `PppPackage` customer SAAT proses Perpanjang terjadi
 *   (snapshot, bukan harga sekarang kalau berubah nanti).
 * - `deposit_status` — `belum_setor` / `sudah_setor` (default `belum_setor`
 *   untuk baris titip; NULL untuk skema lain).
 * - `deposited_at`   — kapan admin menandai sudah setor.
 * - `deposited_by`   — admin yang menandainya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->decimal('gross_amount', 12, 2)->nullable()->after('amount');
            $table->string('deposit_status')->nullable()->after('gross_amount');
            $table->timestamp('deposited_at')->nullable()->after('deposit_status');
            $table->foreignId('deposited_by')->nullable()->after('deposited_at')
                ->constrained('users')->nullOnDelete();
            $table->index(['scheme', 'deposit_status']);
        });
    }

    public function down(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->dropIndex(['scheme', 'deposit_status']);
            $table->dropConstrainedForeignId('deposited_by');
            $table->dropColumn(['gross_amount', 'deposit_status', 'deposited_at']);
        });
    }
};
