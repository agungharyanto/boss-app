<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.9.11 lanjutan — generalisasi hardcode global "tanggal 5-7" (payout
 * komisi bulanan, `CommissionPayoutService`) jadi konfigurasi PER
 * `CommissionRate` (per paket), bukan satu aturan global untuk semua.
 *
 * `payout_window_start_day`/`payout_window_end_day` (integer 1-31,
 * nullable) — KEDUANYA NULL (default) = komisi dari rate ini bisa dibayar
 * KAPAN SAJA (sama seperti Titip — instan, tanpa jendela waktu). Kalau
 * diisi (mis. 5 dan 7) — komisi dari rate ini HANYA bisa dibayar kalau
 * tanggal hari ini ada di rentang itu.
 *
 * Data existing: paket `HomeFixed-10Mbps` (satu-satunya `CommissionRate`
 * nyata di server dev ini saat migrasi ini ditulis, dipakai sepanjang
 * pengujian sprint v0.9.11 dengan asumsi jendela global 5-7) diisi
 * eksplisit 5/7 di sini supaya perilaku existing TIDAK berubah begitu
 * hardcode global dicabut. Dicocokkan by NAME (bukan id) — aman no-op
 * di instalasi baru manapun yang belum punya paket bernama ini sama
 * sekali (`PppPackage` tidak pernah di-seed, selalu dibuat manual lewat
 * UI — lihat CLAUDE.md v0.14.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_rates', function (Blueprint $table) {
            $table->unsignedTinyInteger('payout_window_start_day')->nullable()->after('titip_amount');
            $table->unsignedTinyInteger('payout_window_end_day')->nullable()->after('payout_window_start_day');
        });

        DB::table('commission_rates')
            ->join('ppp_packages', 'ppp_packages.id', '=', 'commission_rates.ppp_package_id')
            ->where('ppp_packages.name', 'HomeFixed-10Mbps')
            ->update([
                'commission_rates.payout_window_start_day' => 5,
                'commission_rates.payout_window_end_day' => 7,
            ]);
    }

    public function down(): void
    {
        Schema::table('commission_rates', function (Blueprint $table) {
            $table->dropColumn(['payout_window_start_day', 'payout_window_end_day']);
        });
    }
};
