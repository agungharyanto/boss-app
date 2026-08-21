<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registry of OLT access credentials — reachability is always via an
     * existing `nas` row's router (nas_id required), never a direct public
     * IP from boss-app itself, matching how these OLTs actually sit on a
     * private management network behind a Mikrotik (see
     * App\Services\Network\OltDeviceService::testConnection(), which
     * reuses RouterOsGateway::pingHost() — the exact same mechanism
     * CpeDeviceStatusSyncService used to use before its v0.7.7 hybrid
     * rewrite). Same tenant/reseller-scoping shape as `nas` (reseller_id
     * nullable = owned directly by the ISP).
     *
     * Credential columns are nullable per-protocol on purpose — only the
     * set matching `access_protocol` is ever actually filled; the others
     * stay null rather than being repurposed/shared across protocols.
     */
    public function up(): void
    {
        Schema::create('olt_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reseller_id')->nullable()->constrained('resellers')->nullOnDelete();

            $table->string('name');
            $table->foreignId('nas_id')->constrained('nas')->restrictOnDelete();
            $table->string('ip_address');
            $table->foreignId('olt_model_id')->constrained('olt_models')->restrictOnDelete();
            $table->string('access_protocol');

            $table->unsignedInteger('telnet_port')->nullable();
            $table->string('telnet_username')->nullable();
            $table->text('telnet_password')->nullable(); // cast 'encrypted'

            $table->unsignedInteger('ssh_port')->nullable();
            $table->string('ssh_username')->nullable();
            $table->text('ssh_password')->nullable(); // cast 'encrypted'

            $table->string('snmp_version')->nullable();
            $table->unsignedInteger('snmp_port')->nullable();
            $table->text('snmp_ro_community')->nullable(); // cast 'encrypted'
            $table->text('snmp_rw_community')->nullable(); // cast 'encrypted'

            $table->timestamp('last_connection_test_at')->nullable();
            $table->string('last_connection_test_result')->nullable();
            $table->text('last_connection_test_message')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'reseller_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('olt_devices');
    }
};
