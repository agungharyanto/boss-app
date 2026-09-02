<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.9.5 (redesain append-per-invoice) — `commission_ledger.invoice_id`.
 *
 * Menandai baris komisi yang lahir/matang dari sebuah invoice lunas
 * tertentu. NULL = baris "template" yang dibuat saat registrasi (v0.9.4),
 * belum tersambung ke invoice mana pun. `nullOnDelete` — menghapus invoice
 * tidak menghapus jejak komisi yang sudah diperoleh referrer.
 *
 * Indeks unik parsial (`WHERE invoice_id IS NOT NULL`) jadi penjaga
 * idempotensi: satu invoice = maksimal satu baris komisi; banyak baris
 * template (invoice_id NULL) tetap boleh berdampingan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX commission_ledger_invoice_id_unique ON commission_ledger (invoice_id) WHERE invoice_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS commission_ledger_invoice_id_unique');

        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
        });
    }
};
