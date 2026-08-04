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
        Schema::table('vpn_accounts', function (Blueprint $table) {
            // WireGuard peer public key — NOT secret (a public key is safe
            // to store plain, unlike cert_serial's private counterpart,
            // which is never stored at all — see VpnProvisioningService's
            // WireGuard provisioning: the private key is generated
            // server-side and returned ONCE in the API response, never
            // persisted anywhere).
            $table->text('public_key')->nullable()->after('cert_serial');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vpn_accounts', function (Blueprint $table) {
            $table->dropColumn('public_key');
        });
    }
};
