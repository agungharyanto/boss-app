<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi Grup Profil — interface/VLAN binding + PPPoE Server, per diskusi
 * Winbox real dengan Agung. Hanya relevan untuk type=ppp (binding interface
 * Hotspot beda konsepnya — /ip hotspot SERVER, bukan /interface/pppoe-server/
 * server — tidak disentuh sub-versi ini). Nullable: 3 baris Grup Profil yang
 * sudah ada butuh diedit manual oleh Agung untuk diisi; push PPPoE Server
 * hanya dilakukan kalau KEDUA field terisi (lihat PushNetworkProfileGroupToMikrotikJob).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_profile_groups', function (Blueprint $table) {
            $table->string('interface_name')->nullable()->after('type');
            $table->string('service_name')->nullable()->after('interface_name');
        });
    }

    public function down(): void
    {
        Schema::table('network_profile_groups', function (Blueprint $table) {
            $table->dropColumn(['interface_name', 'service_name']);
        });
    }
};
