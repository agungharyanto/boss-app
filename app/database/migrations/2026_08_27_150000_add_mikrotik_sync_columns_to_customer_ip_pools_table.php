<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.14.2.1 — RouterOS live-push, starting with CustomerIpPool (the
 * simplest entity in the "Profil Paket" cluster) — a pattern to be
 * generalized to Bandwidth Profile/Grup Profil/Profil Hotspot/Profil PPP
 * in later sub-versions, NOT built generically here on purpose (see
 * CLAUDE.md's own "RouterOS Live-Push" section for the full reasoning).
 * A plain string column, not a DB-level enum type — same portability
 * reasoning as every other Laravel-enum-backed column in this codebase
 * (App\Enums\MikrotikSyncStatus casts it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_ip_pools', function (Blueprint $table) {
            $table->string('mikrotik_sync_status')->default('pending')->after('is_active');
            $table->timestamp('mikrotik_synced_at')->nullable()->after('mikrotik_sync_status');
            $table->text('mikrotik_sync_error')->nullable()->after('mikrotik_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_ip_pools', function (Blueprint $table) {
            $table->dropColumn(['mikrotik_sync_status', 'mikrotik_synced_at', 'mikrotik_sync_error']);
        });
    }
};
