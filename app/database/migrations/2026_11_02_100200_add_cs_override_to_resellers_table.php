<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.19.1 — reseller boleh override NOMOR CS saja (keputusan terkunci
 * Agung). Brand, logo, legal_name, NPWP, rekening, alamat TETAP milik
 * tenant (lihat company_profiles). Ditaruh sebagai kolom langsung di
 * `resellers` (bukan tabel pendamping) karena resellers sudah menyimpan
 * kontak lain (email/phone/address) sebagai kolom langsung — ikut pola
 * yang ada. CompanyProfileService me-resolve: override reseller → CS tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->string('cs_whatsapp')->nullable()->after('phone');
            $table->string('cs_phone')->nullable()->after('cs_whatsapp');
        });
    }

    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->dropColumn(['cs_whatsapp', 'cs_phone']);
        });
    }
};
