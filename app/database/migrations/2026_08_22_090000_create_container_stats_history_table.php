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
        Schema::create('container_stats_history', function (Blueprint $table) {
            $table->id();
            // Plain string, not a foreign key — there is no "Container"
            // model/table in boss_db (containers are Docker's own concept,
            // read live via docker-stats-proxy every sync), same posture as
            // reseller_tax_ledger's polymorphic reference_type/reference_id
            // (no FK constraint, no migration needed if the set of
            // containers changes). The container's own Docker name (e.g.
            // "genieacs-cwmp"), not its id — ids change on every recreate,
            // names are the stable identity an admin actually recognizes.
            $table->string('container_name');
            $table->float('cpu_percent')->nullable();
            $table->float('memory_usage_mb')->nullable();
            $table->float('memory_limit_mb')->nullable();
            $table->unsignedBigInteger('network_rx_bytes')->nullable();
            $table->unsignedBigInteger('network_tx_bytes')->nullable();
            // SizeRw (the container's own writable layer) via
            // `GET /containers/json?size=true` — deliberately NOT
            // SizeRootFs (which mostly reflects shared base-image layers,
            // not this container's own growth) — see
            // App\Services\Infra\ContainerStatsService's own docblock.
            $table->float('disk_usage_mb')->nullable();
            $table->timestamp('recorded_at');

            // Deliberately no Laravel timestamps() — same "write-once,
            // recorded_at is what matters" reasoning as cpe_signal_history
            // (v0.8.3). No retention/pruning built yet — same accepted,
            // documented gap as that table, see CLAUDE.md "Container Stats
            // via docker-socket-proxy (v0.8.4 Bagian C)".
            $table->index(['container_name', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('container_stats_history');
    }
};
