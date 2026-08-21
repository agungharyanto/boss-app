<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v0.8.1 — replaces the single shared WireGuard tunnel gateway
     * (172.23.195.1, one address for every NAS, see CidrRange::
     * gatewayAddress()) with a dedicated /30 block PER NAS
     * (172.23.195.0/30 for the first NAS ever provisioned, .4/30 for the
     * second, etc — .1/.5/... = that NAS's own gateway, .2/.6/... = the
     * router's own tunnel address). Real router-side investigation
     * (Agung, networking) found the shared-gateway /32 scheme means the
     * router only knows how to reach the tunnel via WireGuard's own
     * AllowedIPs-driven implicit routing, never a real connected route —
     * suspected (not yet conclusively proven) contributor to traffic
     * arriving at the router (confirmed via peer rx byte counters) but
     * not being forwarded onward to a NAS's LAN.
     *
     * DELIBERATELY STICKY, not a release-and-reuse pool like
     * vpn_ip_pool — a NAS keeps the SAME block across every
     * revoke/reprovision cycle of its WireGuard account (this system
     * revokes/reprovisions the same NAS's account routinely — a
     * FCFS-pool-of-blocks model would make the router-side block
     * assignment churn unpredictably every regen, exactly the kind of
     * instability this redesign is trying to remove). One row = one NAS,
     * permanently, once it has ever needed a WireGuard account.
     *
     * block_index is FCFS by first-ever-request order (same "whichever
     * gets there first wins the next slot" semantics vpn_ip_pool already
     * has), NOT derived from nas.id — allocated via lockForUpdate() +
     * MAX(block_index)+1 under vpn_server_id, same locking pattern as
     * vpn_ip_pool/OdpPort (WorkOrderService), not a new locking style.
     *
     * Scoped by vpn_server_id (the WireGuard pool owner row — see
     * VpnServer::poolOwnerFor()) so block_index uniqueness is per-pool,
     * matching how vpn_ip_pool itself is scoped.
     */
    public function up(): void
    {
        Schema::create('vpn_wireguard_nas_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nas_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('vpn_server_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('block_index');
            $table->string('gateway_ip');
            $table->string('router_ip');
            $table->timestamps();

            $table->unique(['vpn_server_id', 'block_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vpn_wireguard_nas_blocks');
    }
};
