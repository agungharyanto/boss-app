<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi Prioritas Dropdown — sama persis dengan migration untuk
 * `hotspot_packages` (lihat docblock migration itu untuk verifikasi range
 * 1-8 dan default 8 terhadap `ro-hotspot.bajastu.id` — tidak diulang di
 * sini, satu sumber kebenaran teknis yang sama berlaku untuk kedua tabel,
 * keduanya push priority lewat mekanisme `/ppp profile` yang identik).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ppp_packages', function (Blueprint $table) {
            $table->unsignedTinyInteger('priority_new')->default(8)->after('priority');
        });

        DB::table('ppp_packages')->orderBy('id')->each(function ($row) {
            $old = (string) $row->priority;
            $new = (is_numeric($old) && (int) $old >= 1 && (int) $old <= 8) ? (int) $old : 8;

            DB::table('ppp_packages')->where('id', $row->id)->update(['priority_new' => $new]);
        });

        Schema::table('ppp_packages', function (Blueprint $table) {
            $table->dropColumn('priority');
        });

        Schema::table('ppp_packages', function (Blueprint $table) {
            $table->renameColumn('priority_new', 'priority');
        });
    }

    public function down(): void
    {
        Schema::table('ppp_packages', function (Blueprint $table) {
            $table->string('priority_old')->default('Default')->after('priority');
        });

        DB::table('ppp_packages')->orderBy('id')->each(function ($row) {
            DB::table('ppp_packages')->where('id', $row->id)->update(['priority_old' => (string) $row->priority]);
        });

        Schema::table('ppp_packages', function (Blueprint $table) {
            $table->dropColumn('priority');
        });

        Schema::table('ppp_packages', function (Blueprint $table) {
            $table->renameColumn('priority_old', 'priority');
        });
    }
};
