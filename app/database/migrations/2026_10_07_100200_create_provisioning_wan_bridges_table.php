<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Template "WAN Bridge" (ZTP v0.23.8.3) — 0..1 ke provisioning_profile. Serupa
 * WAN Internet tapi TANPA PPPoE/NAT (bridge murni). Section D4
 * docs/omci/ztp-dynamic-config-design.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_wan_bridges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provisioning_profile_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('vlan');
            $table->string('service_type', 32)->default('INTERNET');
            $table->string('wifi_role', 16)->default('guest_wifi'); // guest_wifi | none
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_wan_bridges');
    }
};
