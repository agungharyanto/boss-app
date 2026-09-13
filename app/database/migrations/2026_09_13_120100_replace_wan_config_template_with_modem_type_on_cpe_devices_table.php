<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.12.5 (revisi lagi, dikonfirmasi Agung) — section Detail Perangkat CPE
 * assign Tipe Modem LANGSUNG ke device, bukan Template. Template
 * di-resolve ON-DEMAND (v0.12.6, saat push) dari kombinasi
 * `customers.ppp_package_id` + `cpe_devices.modem_type_id` — TIDAK
 * disimpan permanen di device (keputusan eksplisit: Template yang
 * berubah/dihapus tidak perlu update manual ke device manapun).
 *
 * Migration BARU (bukan edit migration
 * `2026_09_13_100200_add_wan_config_template_id_to_cpe_devices_table`/
 * `..._100300_add_wan_config_template_source_...` yang sudah di-commit
 * sebelumnya di branch ini) — mengganti kedua kolom itu dengan
 * `modem_type_id`/`modem_type_source`. Dikonfirmasi 0 baris
 * `cpe_devices` yang punya `wan_config_template_id` terisi sebelum
 * migration ini (dicek langsung) — tidak ada data yang hilang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cpe_devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wan_config_template_id');
            $table->dropColumn('wan_config_template_source');
        });

        Schema::table('cpe_devices', function (Blueprint $table) {
            $table->foreignId('modem_type_id')
                ->nullable()
                ->after('model_name')
                ->constrained('modem_types')
                ->nullOnDelete();
            $table->string('modem_type_source')->nullable()->after('modem_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('cpe_devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('modem_type_id');
            $table->dropColumn('modem_type_source');
        });

        Schema::table('cpe_devices', function (Blueprint $table) {
            $table->foreignId('wan_config_template_id')
                ->nullable()
                ->after('model_name')
                ->constrained('wan_config_templates')
                ->nullOnDelete();
            $table->string('wan_config_template_source')->nullable()->after('wan_config_template_id');
        });
    }
};
