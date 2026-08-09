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
        Schema::table('nas', function (Blueprint $table) {
            // v0.7.3 — CIDR of this NAS's TR-069 management VLAN (e.g.
            // "10.1.0.0/20"), used to widen the WireGuard tunnel's
            // AllowedIPs + a scoped firewall exception so GenieACS
            // Connection Request can reach CPE behind this NAS. Deliberately
            // named "tr069_management_subnet", not "ont_lan_subnet" — this
            // is the NAS's own management VLAN (confirmed for real against
            // test-x86-bajastu: interface vlan9-TR069, address 10.1.0.1/20),
            // not a customer-facing LAN concept. Nullable — most NAS rows
            // won't have Connection Request routing set up.
            $table->string('tr069_management_subnet')->nullable()->after('mikrotik_ip');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nas', function (Blueprint $table) {
            $table->dropColumn('tr069_management_subnet');
        });
    }
};
