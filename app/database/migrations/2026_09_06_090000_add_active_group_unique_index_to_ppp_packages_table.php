<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v0.14.5.4 amendment — ATURAN KERAS 1:1 Grup Profil <-> Profil PPP
 * (dikonfirmasi Agung).
 *
 * Satu Grup Profil HANYA BOLEH dipakai oleh SATU Profil PPP aktif. Alasan
 * teknis: satu `/ppp profile` di RouterOS cuma bisa punya satu `rate-limit`
 * — tidak bisa menampung limitasi banyak paket sekaligus. Grup Profil dan
 * Profil PPP-nya sekarang berbagi SATU objek `/ppp profile` (di-lookup by
 * comment Grup Profil), Profil PPP hanya menambahkan `rate-limit` +
 * `session-timeout` ke profile itu — bukan push objek `/ppp profile`
 * terpisah seperti sebelumnya.
 *
 * Partial unique index: hanya baris AKTIF (`deleted_at IS NULL AND
 * is_active`) yang di-unique-kan — Profil PPP yang sudah di-soft-delete
 * ATAU dinonaktifkan (`is_active = false`) tidak menghalangi Grup Profil-nya
 * dipakai Profil PPP baru. Predikat boolean bare (`AND is_active`) portable
 * ke Postgres (konteks boolean) dan SQLite (nilai non-nol = true).
 *
 * Data dev saat migration ini dibuat: cuma 1 PppPackage genuinely-live
 * (#16 pada group #27); semua yang lain sudah soft-deleted — nol konflik,
 * tidak perlu migrasi data.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX ppp_packages_active_group_unique '.
            'ON ppp_packages (network_profile_group_id) '.
            'WHERE deleted_at IS NULL AND is_active'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ppp_packages_active_group_unique');
    }
};
