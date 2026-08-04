<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Platform-level, deliberately NOT tenant/reseller-scoped — same
        // posture as payment_gateway_settings/whatsapp_gateway_settings:
        // one shared VPN infrastructure serves the whole ISP. Schema is
        // pool-ready for N>1 nodes (v0.6.4 health-check/failover) even
        // though only one row is ever populated this sprint.
        Schema::create('vpn_servers', function (Blueprint $table) {
            $table->id();
            $table->string('hostname');
            $table->string('public_ip');
            // CIDR of the internal VPN client subnet this node hands out
            // (e.g. "172.23.194.0/24") — VpnServer::provisionIpPool() reads
            // this to generate the vpn_ip_pool rows below. Not part of the
            // original spec column list, but IP allocation has no way to
            // know its own boundaries without it.
            $table->string('subnet_cidr');
            $table->json('protocol_support')->default(json_encode(['openvpn']));
            $table->unsignedInteger('max_clients')->default(250);
            $table->unsignedInteger('current_clients')->default(0);
            $table->string('status')->default('offline');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vpn_servers');
    }
};
