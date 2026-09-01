<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.9.3 — Commission Rate Settings. Satu baris konfigurasi rate komisi per
 * PppPackage (v0.14.5). FK ke ppp_packages SAJA — komisi hanya untuk paket
 * bulanan PPP; Hotspot/Token pakai konsep "Agent" terpisah yang di luar
 * scope cluster v0.9.x (lihat ReferrerType::Admin vs modul Agent yang belum
 * ada).
 *
 * 3 skema komisi, semua opsional tapi minimal 1 wajib diisi (divalidasi di
 * FormRequest/Livewire, bukan di DB — sebuah baris dengan ketiganya null
 * secara struktural sah, cuma tidak boleh dibuat lewat jalur normal):
 *  - recurring_amount        — Komisi Per Bulan (dibayar tiap siklus tagih).
 *  - limited_count_amount +  — Komisi skema "X-kali pembayaran": nominal per
 *    limited_count_times        pembayaran, untuk `limited_count_times` kali
 *                               pertama saja. `times` FLEKSIBEL (admin isi
 *                               sendiri, tidak fixed ke 2). Pasangan —
 *                               keduanya terisi atau keduanya kosong.
 *  - titip_amount            — Komisi Titip (one-off).
 *
 * Unique parsial `WHERE deleted_at IS NULL` (bukan unique polos) — sama pola
 * dengan bandwidth_profiles/customer_ip_pools: satu rate aktif per paket,
 * tapi rate yang sudah di-soft-delete tidak memblokir pembuatan yang baru.
 * ppp_packages.id sendiri sudah unik lintas tenant (tiap paket milik satu
 * tenant), jadi cukup unik di ppp_package_id — tidak perlu (tenant_id, ppp_package_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ppp_package_id')->constrained()->cascadeOnDelete();
            $table->decimal('recurring_amount', 12, 2)->nullable();
            $table->decimal('limited_count_amount', 12, 2)->nullable();
            $table->unsignedInteger('limited_count_times')->nullable();
            $table->decimal('titip_amount', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
        });

        DB::statement(
            'CREATE UNIQUE INDEX commission_rates_ppp_package_id_unique '.
            'ON commission_rates (ppp_package_id) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rates');
    }
};
