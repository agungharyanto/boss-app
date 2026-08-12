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
        Schema::table('cpe_devices', function (Blueprint $table) {
            // Only touched by App\Services\Network\CpeDeviceStatusSyncService
            // when `status` actually FLIPS (online<->offline) — never on
            // every sync run, so "Online Duration" in the UI
            // (now - status_changed_at) means what it says, not "time since
            // the last periodic check happened to run".
            $table->timestamp('status_changed_at')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cpe_devices', function (Blueprint $table) {
            $table->dropColumn('status_changed_at');
        });
    }
};
