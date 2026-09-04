<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint "perpanjang-daftar-pelanggan" (Perpanjang Multi-Bulan) —
 * `commission_ledger.referrer_id` dijadikan NULLABLE.
 *
 * Alasan: aksi "Perpanjang" multi-bulan oleh admin back-office (yang TIDAK
 * tertaut Referrer manapun) tetap membuat N baris `commission_ledger`
 * scheme=titip sebagai penanda "periode X sudah dibayar" (dipakai
 * pengecekan anti-duplikat). Baris seperti itu tidak punya Referrer —
 * `amount` NULL (tidak ada komisi), `deposit_status = sudah_setor` (uang
 * masuk langsung ke perusahaan, bukan dipegang Referrer).
 *
 * FK tetap ada (FK memang mengizinkan NULL secara default) —
 * hanya constraint NOT NULL yang dilepas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->unsignedBigInteger('referrer_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->unsignedBigInteger('referrer_id')->nullable(false)->change();
        });
    }
};
