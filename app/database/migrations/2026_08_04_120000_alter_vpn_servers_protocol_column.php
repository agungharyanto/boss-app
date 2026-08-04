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
        // v0.6.3 architecture decision (confirmed explicitly before writing
        // this migration): WireGuard and L2TP/IPsec run in SEPARATE
        // containers from OpenVPN (different kernel/daemon requirements
        // entirely — matches this repo's single-responsibility container
        // pattern, e.g. freeradius-db separate from freeradius). That makes
        // `status`/`current_clients` fundamentally a per-daemon concept, not
        // a per-host one — `protocol_support` (a json array implying one row
        // could represent several simultaneously-running protocols) doesn't
        // model that. Replaced with a plain `protocol` column: one
        // vpn_servers row per (host, protocol) pair from now on.
        Schema::table('vpn_servers', function (Blueprint $table) {
            $table->string('protocol')->nullable()->after('subnet_cidr');
        });

        // Backfill: the v0.6.2 row's protocol_support was always exactly
        // ["openvpn"] (OpenVPN was the only protocol that sprint built) —
        // no ambiguity to resolve here.
        DB::table('vpn_servers')->update(['protocol' => 'openvpn']);

        Schema::table('vpn_servers', function (Blueprint $table) {
            $table->string('protocol')->nullable(false)->change();
            $table->dropColumn('protocol_support');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vpn_servers', function (Blueprint $table) {
            $table->json('protocol_support')->nullable()->after('subnet_cidr');
        });

        foreach (DB::table('vpn_servers')->select('id', 'protocol')->get() as $row) {
            DB::table('vpn_servers')->where('id', $row->id)->update([
                'protocol_support' => json_encode([$row->protocol]),
            ]);
        }

        Schema::table('vpn_servers', function (Blueprint $table) {
            $table->dropColumn('protocol');
        });
    }
};
