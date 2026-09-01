<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi Prioritas Dropdown — `priority` (dulu string bebas, default
 * 'Default') diganti jadi integer, sesuai range asli RouterOS Queue
 * Priority yang dikonfirmasi LANGSUNG ke `ro-hotspot.bajastu.id` (RouterOS
 * 7.12.1) sebelum migration ini ditulis, BUKAN diasumsikan dari perkiraan
 * Agung (1-7) — pesan error `/queue/simple/add` sendiri: "value of
 * upload-priority out of range (1..8)". Default baru: **8** — dikonfirmasi
 * juga LANGSUNG sebagai nilai default RouterOS SENDIRI ketika field priority
 * genuinely tidak pernah di-set sama sekali (baris `/queue simple` baru
 * tanpa `priority=` terbaca kembali `8/8`), bukan angka tengah yang ditebak.
 *
 * `/ppp profile`/`/ip hotspot user profile` TIDAK punya parameter
 * `priority` berdiri sendiri (dikonfirmasi live, "unknown parameter
 * priority" di kedua object) — satu-satunya cara push priority per-profil
 * ke RouterOS adalah lewat slot ke-5 di syntax `rate-limit` extended
 * (`rx-rate/tx-rate rx-burst-rate/tx-burst-rate rx-burst-threshold/
 * tx-burst-threshold rx-burst-time/tx-burst-time priority`) — dikonfirmasi
 * genuinely diterima & tersimpan lewat live add/set round-trip. Lihat
 * `PushHotspotPackageToMikrotikJob`/`PushPppPackageToMikrotikJob` untuk
 * bagaimana slot ini benar-benar dipakai.
 *
 * Cara migrasi data existing: dev DB nyata cuma punya 1 baris ('Default')
 * di tabel ini — dicek langsung sebelum menulis backfill ini, bukan
 * diasumsikan kosong. Backfill mencoba cast string lama ke integer 1-8
 * yang valid kalau memang angka (jaga-jaga data masa depan), fallback ke
 * 8 (default RouterOS sendiri) untuk string non-numerik seperti 'Default'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotspot_packages', function (Blueprint $table) {
            $table->unsignedTinyInteger('priority_new')->default(8)->after('priority');
        });

        DB::table('hotspot_packages')->orderBy('id')->each(function ($row) {
            $old = (string) $row->priority;
            $new = (is_numeric($old) && (int) $old >= 1 && (int) $old <= 8) ? (int) $old : 8;

            DB::table('hotspot_packages')->where('id', $row->id)->update(['priority_new' => $new]);
        });

        Schema::table('hotspot_packages', function (Blueprint $table) {
            $table->dropColumn('priority');
        });

        Schema::table('hotspot_packages', function (Blueprint $table) {
            $table->renameColumn('priority_new', 'priority');
        });
    }

    public function down(): void
    {
        Schema::table('hotspot_packages', function (Blueprint $table) {
            $table->string('priority_old')->default('Default')->after('priority');
        });

        // Portable across Postgres/SQLite (no DB::raw()/native cast syntax)
        // — same "fetch in PHP, cast in PHP" idiom as up() above.
        DB::table('hotspot_packages')->orderBy('id')->each(function ($row) {
            DB::table('hotspot_packages')->where('id', $row->id)->update(['priority_old' => (string) $row->priority]);
        });

        Schema::table('hotspot_packages', function (Blueprint $table) {
            $table->dropColumn('priority');
        });

        Schema::table('hotspot_packages', function (Blueprint $table) {
            $table->renameColumn('priority_old', 'priority');
        });
    }
};
