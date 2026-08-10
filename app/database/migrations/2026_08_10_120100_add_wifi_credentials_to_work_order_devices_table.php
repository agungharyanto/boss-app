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
        // v0.7.5 — the technician-input value that gets pushed to the
        // device via CpeActionService once it's actually bound to GenieACS
        // (see App\Services\Network\CpeBindingService::provisionWifiIfPending()).
        // wifi_password is a real, retrievable credential (needs to be sent
        // to the device later, unlike App\Models\CpeActionLog.parameters'
        // one-way sha256 fingerprint) — 'encrypted' cast, same posture as
        // Nas::api_password/radius_secret.
        Schema::table('work_order_devices', function (Blueprint $table) {
            $table->string('ssid')->nullable()->after('serial_number');
            $table->text('wifi_password')->nullable()->after('ssid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_order_devices', function (Blueprint $table) {
            $table->dropColumn(['ssid', 'wifi_password']);
        });
    }
};
