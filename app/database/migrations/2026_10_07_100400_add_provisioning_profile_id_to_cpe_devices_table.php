<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Override profil ZTP per-CPE (v0.23.8.3, keputusan #2) — nullable, MENANG di
 * atas profil paket saat resolusi. Null = pakai resolusi paket. Section D6
 * docs/omci/ztp-dynamic-config-design.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cpe_devices', function (Blueprint $table) {
            $table->foreignId('provisioning_profile_id')->nullable()->after('modem_type_source')
                ->constrained('provisioning_profiles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cpe_devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('provisioning_profile_id');
        });
    }
};
