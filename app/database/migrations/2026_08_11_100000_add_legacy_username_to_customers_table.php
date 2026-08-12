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
        Schema::table('customers', function (Blueprint $table) {
            // MixRadius' own login username — usually the same string as
            // phone_number but not guaranteed (confirmed empirically against
            // the real 561-row export: most rows match, some don't). Kept as
            // its own column rather than assumed-equal-to-phone_number
            // because it's the explicit join key into
            // legacy_mac_customer_map (App\Services\Network\
            // LegacyDeviceMatcherService matches a GenieACS device's serial
            // to a MAC, then a MAC to a legacy_username, then a
            // legacy_username to this column — never phone_number directly).
            $table->string('legacy_username')->nullable()->after('legacy_mixradius_member_id');
            $table->index('legacy_username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['legacy_username']);
            $table->dropColumn('legacy_username');
        });
    }
};
