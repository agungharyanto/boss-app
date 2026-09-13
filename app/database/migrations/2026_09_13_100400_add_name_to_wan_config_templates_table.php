<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.12.5 (revisi arsitektur) — TAMBAHAN di luar daftar literal Langkah 1,
 * tapi turunan logis langsung dari instruksi sendiri: "boleh beberapa
 * Template untuk Tipe Modem yang sama, nama yang membedakan — sesuai
 * 'nama bebas'". Skema asli `wan_config_templates` (v0.12.4) tidak punya
 * kolom `name` sama sekali (dulu dibedakan lewat kombinasi Paket+Modem,
 * tanpa perlu nama). Tanpa kolom ini, dua template untuk Tipe Modem yang
 * sama tidak bisa dibedakan sama sekali di dropdown/list.
 *
 * SENGAJA TIDAK unique — "nama bebas" berarti tanpa constraint ketat,
 * hanya label tampilan untuk membantu admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wan_config_templates', function (Blueprint $table) {
            $table->string('name')->default('')->after('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('wan_config_templates', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
