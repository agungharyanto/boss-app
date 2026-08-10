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
        Schema::create('cpe_connected_hosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cpe_device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reseller_id')->nullable()->constrained('resellers')->nullOnDelete();
            $table->string('mac_address');
            // Often empty on real devices (see CLAUDE.md's v0.7.6 discovery
            // notes) — nullable, never assumed present.
            $table->string('hostname')->nullable();
            $table->string('ip_address')->nullable();
            // Snapshot from the MOST RECENT sync — App\Services\Network\
            // CpeConnectedHostsService::syncFromGenieAcs() flips this to
            // false (never deletes the row) the moment a previously-seen MAC
            // stops appearing in GenieACS's own stored Hosts.Host object.
            $table->boolean('is_active')->default(true);
            // Set exactly once, on first insert — never touched again.
            $table->timestamp('first_seen_at');
            // Updated on every sync where this MAC is still present.
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->index('mac_address');
            $table->index(['cpe_device_id', 'last_seen_at']);
            $table->unique(['cpe_device_id', 'mac_address']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cpe_connected_hosts');
    }
};
