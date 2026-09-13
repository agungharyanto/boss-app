<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.12.5 (revisi arsitektur) — Template Konfig CPE ter-assign ke device,
 * BUKAN ke Paket. Terisi lewat auto-suggest (WanConfigTemplateSuggestionService,
 * best-effort — lihat docblock-nya sendiri) SAAT device di-bind (v0.7.5
 * flow) atau override manual admin di Detail Perangkat CPE.
 *
 * `nullOnDelete()` (bukan restrictOnDelete()) — sama alasan
 * `work_order_devices.modem_type_id` (v0.12.4): field historis/asigment
 * device, bukan konfigurasi live yang wajib diblokir hapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cpe_devices', function (Blueprint $table) {
            $table->foreignId('wan_config_template_id')
                ->nullable()
                ->after('model_name')
                ->constrained('wan_config_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cpe_devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wan_config_template_id');
        });
    }
};
