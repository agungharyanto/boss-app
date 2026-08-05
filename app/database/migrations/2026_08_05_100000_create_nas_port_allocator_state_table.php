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
        // Singleton row (id=1), same "lockable row" idiom as vpn_ip_pool/
        // odp_ports (one lockable resource per allocation) — except here
        // there's nothing to individually enumerate ahead of time (ports
        // aren't rows you can pre-seed like IPs/ODP slots), so instead this
        // is a single counter row locked via lockForUpdate() inside a
        // transaction (same portable pattern as payment_gateway_settings'
        // singleton row), NOT a Postgres-only advisory lock — works
        // identically on the sqlite test connection.
        Schema::create('nas_port_allocator_state', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('next_auth_port');
            $table->timestamps();
        });

        // 20000 start: clear of every port already in use elsewhere in this
        // stack (80/443 nginx, 1194-1196 openvpn, 51820-51822 wireguard,
        // 500/1701/4500 l2tp, 1812/1813/3799 FreeRADIUS's own stock
        // default+CoA, 5432/6379/27017/3306 DB/cache per BOSS-010's never-
        // exposed list) — AND, found for real only after actually deploying
        // this against the running freeradius container (not just reading
        // config files): FreeRADIUS's OWN stock `inner-tunnel` virtual
        // server permanently reserves 127.0.0.1:18120 for internal EAP
        // testing (raddb/sites-enabled/inner-tunnel, untouched default) —
        // an allocator range starting at 18120 collided with it on the
        // very first real sync() (radiusd refused to (re)bind: "Address in
        // use"). 20000 has no such stock reservation (checked via `grep -rn
        // "port = [0-9]"` across the whole raddb tree, the only other
        // hardcoded values are 1812/1813 and inner-tunnel's 18120). See
        // docs/ROADMAP.md v0.6.5 for the full range note. Step of 10 per
        // NAS (auth=+0, acct=+1, +2..+9 reserved headroom) so a future
        // per-NAS port need never collides with the next NAS's block even
        // if this allocator's step spacing is revisited later.
        DB::table('nas_port_allocator_state')->insert([
            'id' => 1,
            'next_auth_port' => 20000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // auth_port/acct_port only — NOT coa_port. Found via a real router
        // (`/radius/incoming/print`) before CoaService was built: RouterOS's
        // CoA listener port is a single ROUTER-WIDE setting, not tied to
        // any specific `/radius` (server) entry the way authentication-
        // port/accounting-port are — there's no FreeRADIUS-side collision
        // to avoid the way auth/acct have (many NAS sharing one FreeRADIUS,
        // indistinguishable by source IP through VPN MASQUERADE), so
        // coa_port has no reason to be unique across NAS. It stays a
        // plain, admin-editable column (default 3799, RFC 5176) — see
        // NasPortAllocatorService's docblock.
        Schema::table('nas', function (Blueprint $table) {
            $table->unique('auth_port');
            $table->unique('acct_port');
        });

        // Backfill any pre-existing NAS rows (v0.6.1-v0.6.4 test/production
        // data) that predate this allocator — ordered by id so the result
        // is deterministic.
        $nextPort = 20000;
        foreach (DB::table('nas')->orderBy('id')->pluck('id') as $nasId) {
            DB::table('nas')->where('id', $nasId)->update([
                'auth_port' => $nextPort,
                'acct_port' => $nextPort + 1,
            ]);
            $nextPort += 10;
        }
        DB::table('nas_port_allocator_state')->where('id', 1)->update(['next_auth_port' => $nextPort]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nas', function (Blueprint $table) {
            $table->dropUnique(['auth_port']);
            $table->dropUnique(['acct_port']);
        });

        Schema::dropIfExists('nas_port_allocator_state');
    }
};
