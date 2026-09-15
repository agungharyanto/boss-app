<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.22.1 — Manajemen User (Staff CRUD). "Disable"/"Enable", bukan
 * "Aktifkan"/"Nonaktifkan" — konvensi penamaan dikunci eksplisit oleh
 * Agung. Soft-disable, BUKAN soft-delete Eloquent — tidak ada `deleted_at`
 * di sini, murni satu flag boolean yang dicek di titik login
 * (Fortify::authenticateUsing(), lihat FortifyServiceProvider).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_disabled')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_disabled');
        });
    }
};
