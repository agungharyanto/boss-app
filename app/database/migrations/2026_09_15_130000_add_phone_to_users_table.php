<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.22.1 (revisi) — nomor HP staff, nullable, TIDAK unique. Login-via-HP
 * baru masuk v0.22.2 — kalau/ketika itu dibangun, butuh migration unique
 * TERPISAH di situ (sengaja tidak diantisipasi di sini, per instruksi
 * eksplisit: staff lama/baru boleh pegang nomor sama untuk saat ini).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
