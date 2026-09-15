<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.22.2 — Login via Nomor HP + Email Opsional. `users.phone` naik jadi
 * alat login utama (harus NOT NULL + UNIQUE dari sini); `users.email`
 * jadi opsional (unique index tetap ada — Postgres/SQLite keduanya
 * mengizinkan banyak NULL di kolom unique).
 *
 * Backfill placeholder SEBELUM constraint NOT NULL+UNIQUE ditambahkan —
 * dikonfirmasi Agung (2026-09-15): 2 akun fixture Technician
 * ("TEST - Jangan Hapus", id 28/29) genuinely belum punya phone sama
 * sekali di titik ini, kemungkinan besar tidak pernah dipakai untuk
 * login (murni fixture Work Order/Technician) — placeholder unik
 * `no-phone-{id}` aman untuk mereka, TAPI berarti akun-akun itu TIDAK
 * BISA login via nomor HP sampai diisi nomor asli. Raw SQL `||` (string
 * concat) portable Postgres DAN SQLite (test suite), tidak butuh loop
 * PHP. Down() TIDAK mengembalikan placeholder ke NULL — backfill data
 * ini irreversible, cuma constraint yang dilepas saat rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE users SET phone = 'no-phone-' || id WHERE phone IS NULL OR phone = ''");

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable(false)->change();
            $table->unique('phone');

            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->string('phone')->nullable()->change();

            $table->string('email')->nullable(false)->change();
        });
    }
};
