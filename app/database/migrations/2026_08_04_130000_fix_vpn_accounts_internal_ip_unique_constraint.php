<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Found via real revoke-then-reprovision testing (v0.6.3 gap-fix
        // pass): the plain global unique('internal_ip') from v0.6.2 applies
        // to EVERY row regardless of status — once an IP was ever assigned
        // to a (now revoked) account, that exact address could never be
        // reused, even though vpn_ip_pool correctly frees it. Only an
        // ACTIVE account should ever hold a given internal_ip at once —
        // same partial-unique-index technique already used for
        // whatsapp_sessions (v0.4.0) for the analogous "unique among a
        // subset of rows" need.
        Schema::table('vpn_accounts', function ($table) {
            $table->dropUnique(['internal_ip']);
        });

        DB::statement(
            "CREATE UNIQUE INDEX vpn_accounts_active_internal_ip_unique ON vpn_accounts (internal_ip) WHERE status = 'active'"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS vpn_accounts_active_internal_ip_unique');

        Schema::table('vpn_accounts', function ($table) {
            $table->unique('internal_ip');
        });
    }
};
