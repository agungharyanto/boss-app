<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same mikrotik_sync_status/mikrotik_synced_at/mikrotik_sync_error pattern
 * already used by CustomerIpPool/NetworkProfileGroup/HotspotPackage —
 * separate migration (not folded into 2026_08_31_090100) since that one has
 * already run and this codebase never edits an already-applied migration.
 * Prefixed `expired_profile_` since `nas` itself has no other sync-tracked
 * concept — this tracks the "Profile Pelanggan Expired" push specifically,
 * independent of anything else on this row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nas', function (Blueprint $table) {
            $table->string('expired_profile_mikrotik_sync_status')->nullable()->after('expired_ip_pool_id');
            $table->timestamp('expired_profile_mikrotik_synced_at')->nullable()->after('expired_profile_mikrotik_sync_status');
            $table->text('expired_profile_mikrotik_sync_error')->nullable()->after('expired_profile_mikrotik_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('nas', function (Blueprint $table) {
            $table->dropColumn(['expired_profile_mikrotik_sync_status', 'expired_profile_mikrotik_synced_at', 'expired_profile_mikrotik_sync_error']);
        });
    }
};
