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
        // No tenant_id/reseller_id of its own — same pattern as
        // odp_ports/work_order_devices/work_order_photos (v0.5.0): scoped
        // implicitly through the parent nas row (which IS reseller-scoped
        // via BelongsToResellerScope).
        Schema::create('vpn_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nas_id')->constrained('nas')->cascadeOnDelete();
            $table->foreignId('vpn_server_id')->constrained()->cascadeOnDelete();
            // Ready for wireguard/l2tp (v0.6.3) — only 'openvpn' is actually
            // provisionable this sprint.
            $table->string('protocol')->default('openvpn');
            $table->string('username'); // = client cert Common Name
            // Not used by OpenVPN (cert-based auth) — reserved for
            // L2TP/IPsec (v0.6.3), which is username/password-based.
            $table->text('password')->nullable(); // cast 'encrypted'
            $table->string('internal_ip');
            // easy-rsa's issued cert serial (from `openssl x509 -noout
            // -serial`) — the lookup key for revoke()/CRL, not the DB id.
            $table->string('cert_serial')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();

            $table->unique('internal_ip');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vpn_accounts');
    }
};
