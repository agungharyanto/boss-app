<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi Grup Profil — "Profile Pelanggan Expired" per NAS, per diskusi
 * Winbox real dengan Agung: satu /ppp profile fallback per NAS (pool
 * terbatas, tanpa rate-limit) untuk pelanggan yang belum/tidak bayar.
 * Ditambahkan langsung ke tabel `nas` (bukan tabel config terpisah) — sama
 * pola dengan kolom-kolom opsional lain yang sudah tumbuh di `nas` lintas
 * sub-versi (mikrotik_ip, tr069_management_subnet, dst), bukan konsep baru.
 * restrictOnDelete — sama reasoning dengan setiap FK ke customer_ip_pools
 * lain di codebase ini (NetworkProfileGroup::customer_ip_pool_id): pool yang
 * masih dipakai sebagai fallback expired tidak boleh hilang begitu saja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nas', function (Blueprint $table) {
            $table->foreignId('expired_ip_pool_id')->nullable()->after('coa_port')->constrained('customer_ip_pools')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('nas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expired_ip_pool_id');
        });
    }
};
