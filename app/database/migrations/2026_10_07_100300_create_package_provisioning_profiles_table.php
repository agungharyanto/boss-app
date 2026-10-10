<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot paket ↔ profil (ZTP v0.23.8.3, keputusan #2 MANY-TO-MANY) — satu paket
 * boleh >1 profil, satu profil boleh dipakai banyak paket. `is_default`
 * menandai profil default paket (aturan pemilihan resolver, section D6
 * docs/omci/ztp-dynamic-config-design.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_provisioning_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ppp_package_id')->constrained('ppp_packages')->cascadeOnDelete();
            $table->foreignId('provisioning_profile_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['ppp_package_id', 'provisioning_profile_id'], 'package_profile_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_provisioning_profiles');
    }
};
