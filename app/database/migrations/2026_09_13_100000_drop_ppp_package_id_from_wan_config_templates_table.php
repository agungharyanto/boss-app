<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.12.5 (revisi arsitektur, dikonfirmasi Agung) — Template Konfig CPE
 * TIDAK terikat Paket sama sekali, dibedakan HANYA oleh Tipe Modem.
 * Migration BARU, bukan edit migration `create_wan_config_templates_table`
 * (v0.12.4) — migration itu sudah di-merge ke `develop` dan sudah
 * dijalankan di DB dev, disiplin "jangan edit migration yang sudah
 * applied" tetap dipegang.
 *
 * Dikonfirmasi 0 baris `wan_config_templates` real di DB dev sebelum
 * migration ini dijalankan (semua sisa data test v0.12.4/v0.12.5 sudah
 * dibersihkan di sesi verifikasi sebelumnya) — tidak ada data yang perlu
 * dimigrasikan/dibackfill.
 *
 * Urutan drop: unique composite -> partial unique index (NULL case) ->
 * FK -> index biasa -> kolom itu sendiri. `modem_type_id` TIDAK disentuh
 * — index biasanya (non-unique) dipertahankan, TIDAK ADA unique
 * constraint baru untuknya (beberapa Template boleh punya Tipe Modem
 * yang sama, dibedakan lewat nama bebas — lihat Template's own docblock).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS wan_config_templates_pkg_null_modem_unique');

        Schema::table('wan_config_templates', function (Blueprint $table) {
            $table->dropUnique('wan_config_templates_pkg_modem_unique');
            $table->dropForeign(['ppp_package_id']);
            $table->dropIndex(['ppp_package_id']);
            $table->dropColumn('ppp_package_id');
        });
    }

    public function down(): void
    {
        Schema::table('wan_config_templates', function (Blueprint $table) {
            $table->foreignId('ppp_package_id')->nullable()->after('tenant_id')->constrained('ppp_packages')->restrictOnDelete();
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
};
