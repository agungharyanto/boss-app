<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // v0.6.4: openvpn_port/wireguard_port were a single GLOBAL config
        // value (config('services.vpn.openvpn_port') etc, see v0.6.3's
        // VpnScriptService) — fine when exactly one node per protocol
        // existed, but a genuine multi-node pool needs each node's own
        // distinct public port (all 3 sibling nodes share the same
        // public_ip this sprint — one physical server — so port is the
        // only thing that actually differentiates which node a Mikrotik
        // script's `connect-to`/`endpoint-port` targets).
        Schema::table('vpn_servers', function (Blueprint $table) {
            $table->unsignedInteger('port')->nullable()->after('public_ip');
        });

        // Backfill the two existing v0.6.2/v0.6.3 rows (openvpn/wireguard
        // node1) with the port their container has always actually used —
        // matches docker-compose.yml's existing host port mapping
        // (1194:1194/udp, 51820:51820/udp) and the old global config
        // defaults, so no behavior changes for already-provisioned accounts.
        DB::table('vpn_servers')->where('protocol', 'openvpn')->update(['port' => 1194]);
        DB::table('vpn_servers')->where('protocol', 'wireguard')->update(['port' => 51820]);
        DB::table('vpn_servers')->where('protocol', 'l2tp_ipsec')->update(['port' => 1701]);

        Schema::table('vpn_servers', function (Blueprint $table) {
            $table->unsignedInteger('port')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vpn_servers', function (Blueprint $table) {
            $table->dropColumn('port');
        });
    }
};
