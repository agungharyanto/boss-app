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
        Schema::create('legacy_mac_customer_map', function (Blueprint $table) {
            $table->id();
            // From MixRadius' own radacct session history (already filtered
            // to real customers, not voucher/hotspot codes, before this
            // table is loaded) — a plain reference table, not tenant-scoped,
            // not tied to any cpe_devices/customers row via FK. Purely a
            // lookup App\Services\Network\LegacyDeviceMatcherService reads
            // from; never written to outside the one-time import command.
            $table->string('mac_address');
            $table->string('legacy_username');
            $table->timestamps();

            $table->index('mac_address');
            $table->index('legacy_username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legacy_mac_customer_map');
    }
};
