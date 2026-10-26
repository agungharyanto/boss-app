<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Template "WAN Internet" (ZTP v0.23.8.3) — 1:1 ke provisioning_profile.
 * Section D3 docs/omci/ztp-dynamic-config-design.md.
 *
 * wan_mode pppoe/dhcp/static dibangun semua (keputusan #4); `untested_on_hardware`
 * (di-sync otomatis dari wan_mode di model) menandai dhcp/static yang BELUM
 * diverifikasi hardware. PPPoE cred TIDAK disimpan sbg nilai mati — `*_strategy`
 * memilih derive-dari-cid vs static (identitas per-pelanggan, bukan template).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_wan_internets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provisioning_profile_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('wan_mode', 16);                 // pppoe | dhcp | static
            $table->boolean('untested_on_hardware')->default(false);
            $table->unsignedSmallInteger('vlan');
            $table->boolean('nat_enabled')->default(true);
            $table->string('service_type', 32)->default('INTERNET'); // X_*_ServiceList
            $table->string('wifi_role', 16)->default('primary_wifi'); // primary_wifi | guest_wifi | none

            // PPPoE (wan_mode=pppoe): strategi kredensial, bukan nilai mati.
            $table->string('pppoe_username_strategy', 16)->nullable(); // derive_from_cid | static
            $table->string('pppoe_username_static')->nullable();
            $table->string('pppoe_password_strategy', 16)->nullable(); // constant | static
            $table->text('pppoe_password_static')->nullable();         // encrypted (cast di model)

            // Static (wan_mode=static) — UNTESTED on hardware.
            $table->string('static_ip', 45)->nullable();
            $table->string('static_gateway', 45)->nullable();
            $table->string('static_netmask', 45)->nullable();
            $table->string('static_dns', 255)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_wan_internets');
    }
};
