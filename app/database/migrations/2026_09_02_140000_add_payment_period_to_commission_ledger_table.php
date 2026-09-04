<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.9.6 — `commission_ledger.payment_period`: tanggal 1 bulan pembayaran
 * yang diwakili baris komisi ini (mis. `2026-09-01` untuk "periode
 * September 2026"). Nullable.
 *
 * Alasan: baris komisi Titip punya `invoice_id` NULL (tidak lahir dari
 * invoice), jadi indeks unik parsial `WHERE invoice_id IS NOT NULL` (v0.9.5)
 * tidak melindunginya dari duplikat. `payment_period` memberi dimensi
 * "pembayaran bulan apa" supaya guard duplikat app-layer (peringatan, bukan
 * hard block — admin/kasus sah tetap boleh 2 entri) bisa bekerja.
 *
 * Baris recurring/limited_count boleh juga mengisinya ke depan, tapi v0.9.6
 * hanya mengisinya untuk baris Titip — baris lama & baris dari
 * `CommissionLedgerMaturityService` tetap `payment_period = NULL`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->date('payment_period')->nullable()->after('scheme');
            $table->index(['referrer_id', 'customer_id', 'payment_period']);
        });
    }

    public function down(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->dropIndex(['referrer_id', 'customer_id', 'payment_period']);
            $table->dropColumn('payment_period');
        });
    }
};
