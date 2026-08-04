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
        // A pre-provisioned pool of individual IP rows (one per usable host
        // address in vpn_servers.subnet_cidr) — same shape as odp_ports
        // (v0.5.0): a finite pool of numbered resources, claimed one at a
        // time under a row lock. VpnServer::provisionIpPool() generates
        // these rows explicitly (not a model event, same reasoning as
        // Odp::provisionPorts() — avoids colliding with factories in tests).
        // Allocating an IP means SELECT ... WHERE status = 'available'
        // LIMIT 1 FOR UPDATE inside a transaction, exactly like
        // OdpPort::whereKey($id)->lockForUpdate() in WorkOrderService —
        // this is what makes concurrent provisioning race-condition-safe
        // without needing to lock the whole vpn_servers row (and therefore
        // serialize unrelated concurrent provisioning attempts).
        Schema::create('vpn_ip_pool', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vpn_server_id')->constrained()->cascadeOnDelete();
            $table->string('ip_address');
            $table->string('status')->default('available');
            $table->foreignId('vpn_account_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['vpn_server_id', 'ip_address']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vpn_ip_pool');
    }
};
