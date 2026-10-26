<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Template SSID per profil ZTP (v0.23.8.4, section D5
 * docs/omci/ztp-dynamic-config-design.md). `ssid_name` disimpan sbg TEMPLATE
 * MENTAH (boleh literal atau placeholder {cid}/{name}) — substitusi saat
 * aktivasi (SsidNameResolver). `password` encrypted, hanya relevan wpa2
 * (dikosongkan paksa saat open — di-enforce di model, bukan cuma UI).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_ssids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provisioning_profile_id')->constrained()->cascadeOnDelete();
            $table->string('wifi_role', 16);    // primary_wifi | guest_wifi
            $table->string('band', 8);          // 2.4g | 5g
            $table->string('ssid_name');        // template mentah (boleh {cid}/{name})
            $table->string('security_mode', 8); // open | wpa2
            $table->text('password')->nullable(); // encrypted (cast di model); null saat open
            $table->timestamps();

            $table->unique(['provisioning_profile_id', 'wifi_role', 'band'], 'provisioning_ssids_profile_role_band_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_ssids');
    }
};
