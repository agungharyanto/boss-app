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
        // v0.7.5 — set the moment CpeBindingService::provisionWifiIfPending()
        // successfully hands the work order's ssid/wifi_password off to
        // CpeActionService::setWifiCredentials(). The ONE guard against
        // pushing the same credentials twice — bindFromWorkOrder() and
        // reconcilePending() can both reach a freshly-online CpeDevice
        // depending on timing, and reconcilePending() runs every 5 minutes.
        Schema::table('cpe_devices', function (Blueprint $table) {
            $table->timestamp('wifi_provisioned_at')->nullable()->after('bound_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cpe_devices', function (Blueprint $table) {
            $table->dropColumn('wifi_provisioned_at');
        });
    }
};
