<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.12.5 (revisi arsitektur) — TAMBAHAN di luar daftar literal Langkah 1
 * instruksi, tapi turunan logis langsung dari kebutuhan Langkah 3.2 ("badge
 * Auto-terdeteksi vs Manual"): tanpa kolom ini, tidak ada cara membedakan
 * apakah `wan_config_template_id` sebuah device berasal dari auto-suggest
 * atau override admin di Detail Perangkat CPE — kedua kolom itu sendiri
 * ambigu (keduanya cuma "sebuah nilai id"). `App\Enums\WanConfigTemplateSource`.
 * Null = belum pernah di-assign sama sekali (dua-duanya null bersamaan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cpe_devices', function (Blueprint $table) {
            $table->string('wan_config_template_source')->nullable()->after('wan_config_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('cpe_devices', function (Blueprint $table) {
            $table->dropColumn('wan_config_template_source');
        });
    }
};
