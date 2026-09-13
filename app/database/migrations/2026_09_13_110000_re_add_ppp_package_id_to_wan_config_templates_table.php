<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.12.5 (koreksi arsitektur — revert sebagian, dikonfirmasi Agung dari
 * klarifikasi chat planning) — Template Konfig CPE BALIK jadi matrix
 * Paket x Tipe Modem, bukan Tipe Modem saja. Migration BARU (bukan edit
 * migration `2026_09_13_100000_drop_ppp_package_id_from_wan_config_templates_table`
 * yang sudah di-merge — disiplin "jangan edit migration yang sudah
 * applied" tetap dipegang, jadi kolom ini DITAMBAH BALIK, bukan
 * migration sebelumnya di-revert/hapus).
 *
 * Dikonfirmasi 0 baris `wan_config_templates` real di DB dev sebelum
 * migration ini (dicek langsung, bukan diasumsikan sama seperti
 * kemarin) — tidak ada data yang perlu di-backfill/di-assign manual.
 *
 * Constraint PERSIS desain awal v0.12.4: unique composite
 * (ppp_package_id, modem_type_id) + partial unique index (ppp_package_id)
 * WHERE modem_type_id IS NULL (satu baris "Default" per Paket). Tipe
 * Modem (katalog OUI matching) TIDAK disentuh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wan_config_templates', function (Blueprint $table) {
            $table->foreignId('ppp_package_id')->after('tenant_id')->constrained('ppp_packages')->restrictOnDelete();
            $table->index('ppp_package_id');
        });

        Schema::table('wan_config_templates', function (Blueprint $table) {
            $table->unique(['ppp_package_id', 'modem_type_id'], 'wan_config_templates_pkg_modem_unique');
        });

        DB::statement(
            'CREATE UNIQUE INDEX wan_config_templates_pkg_null_modem_unique '.
            'ON wan_config_templates (ppp_package_id) WHERE modem_type_id IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS wan_config_templates_pkg_null_modem_unique');

        Schema::table('wan_config_templates', function (Blueprint $table) {
            $table->dropUnique('wan_config_templates_pkg_modem_unique');
            $table->dropForeign(['ppp_package_id']);
            $table->dropIndex(['ppp_package_id']);
            $table->dropColumn('ppp_package_id');
        });
    }
};
