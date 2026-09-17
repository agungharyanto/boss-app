<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v0.22.6 — dipaksa ganti password saat login pertama, untuk staff yang
     * password-nya di-generate random (StaffService::create(), termasuk
     * jalur cleanup akun di v0.22.6). Default `false` — akun yang sudah ada
     * sebelum kolom ini tidak tiba-tiba dipaksa ganti password.
     */
    public function up(): void
    {
        // PostgreSQL tidak mendukung ALTER TABLE ... ADD COLUMN ... AFTER
        // (lihat CLAUDE.md v0.2.0 multi-tenancy) — sengaja tidak dipakai di
        // sini, kolom akan ditambahkan di posisi paling akhir.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
