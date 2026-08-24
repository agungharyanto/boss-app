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
        Schema::create('cpe_signal_history', function (Blueprint $table) {
            $table->id();
            // Deliberately NO tenant_id/reseller_id of its own — scoped
            // implicitly through cpe_device_id, same "child table, no
            // tenant_id copy" posture already established for odp_ports/
            // work_order_devices/work_order_photos (v0.5.0). This table is
            // never queried independently of a specific already-authorized
            // CpeDevice (see CpeDevicePolicy, checked before this table is
            // ever touched), unlike cpe_connected_hosts (v0.7.6), which does
            // carry its own tenant_id/reseller_id copy.
            $table->foreignId('cpe_device_id')->constrained()->cascadeOnDelete();
            // Nullable — a failed refreshObject (timeout, GenieACS error, or
            // this device's model having no cpe_parameter_maps row for
            // rx_power_dbm at all) still writes a row so the history graph
            // can show a genuine gap instead of silently having no data
            // point at all for that poll window — see
            // App\Console\Commands\SyncCpeSignalHistory.
            $table->float('rx_power_dbm')->nullable();
            $table->timestamp('recorded_at');

            // Deliberately no Laravel timestamps() (created_at/updated_at)
            // — every row is write-once, recorded_at IS the timestamp that
            // matters, and this is a high-volume append-only table (every
            // 20 minutes x every online CPE) where two always-redundant
            // columns aren't worth the space. See CLAUDE.md's "RX Power
            // History (v0.8.3)" for the retention/growth open item.
            $table->index(['cpe_device_id', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cpe_signal_history');
    }
};
