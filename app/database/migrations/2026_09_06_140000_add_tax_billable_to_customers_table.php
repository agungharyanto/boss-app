<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint "perpanjang-invoice-asli-cetak" — checklist per-pelanggan (keputusan
 * Agung): "kalau di-checklist maka PPN ditagihkan ke pelanggan berarti harga
 * yang tercantum adalah DPP; kalau tidak di-checklist maka PPN tidak
 * ditagihkan / 0, tetap ada keterangan PPN 0%".
 *
 * `tax_billable = false` (default) → Invoice yang dibuat lewat "Perpanjang"
 * melewati tax engine sepenuhnya: `tax_total = 0`, `grand_total = subtotal`
 * (= `sell_price` paket). Cetakan tetap menampilkan baris "PPN (0%)".
 *
 * `tax_billable = true` → Invoice dihitung normal lewat
 * TaxCalculationService (kontrak v0.3.3): `sell_price` = DPP, PPN
 * ditambahkan di atasnya. Selama Agung belum mengonfigurasi `tax_components`
 * / `reseller_tax_policies`, PPN tetap 0 (tapi jalurnya sudah aktif).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('tax_billable')->default(false)->after('ppp_package_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('tax_billable');
        });
    }
};
