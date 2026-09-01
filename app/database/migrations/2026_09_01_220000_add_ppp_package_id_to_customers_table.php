<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.9.4 — `customers.ppp_package_id`: paket PPP yang dipilih pelanggan,
 * dipakai HANYA untuk menautkan skema/nominal komisi (lewat
 * `commission_rates`). **Sepenuhnya independen dari `subscriptions`** —
 * kolom ini tidak menyentuh billing/invoice sama sekali; billing pelanggan
 * masih manual/MixRadius, BOSS App belum jadi sumber tagihan.
 *
 * Menggantikan pemakaian `customers.package` (varchar bebas, v0.3.0) di
 * form registrasi — kolom `package` lama SENGAJA TIDAK di-drop (semua 551
 * baris existing bernilai NULL, nol risiko, tapi drop kolom = risiko yang
 * tidak perlu). Cukup berhenti dipakai.
 *
 * `nullOnDelete` — sebuah PppPackage yang benar-benar di-force-delete tidak
 * boleh menghapus pelanggan; atribusi paket-nya jadi null saja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('ppp_package_id')
                ->nullable()
                ->after('package')
                ->constrained('ppp_packages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ppp_package_id');
        });
    }
};
