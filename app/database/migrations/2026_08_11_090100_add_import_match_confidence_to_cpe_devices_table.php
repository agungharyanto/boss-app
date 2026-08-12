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
            // How confidently the legacy MixRadius import matched this
            // device's serial number to a customer — 'exact'/'close_1'/
            // 'close_2'. Null for every CpeDevice bound the normal way
            // (App\Services\Network\CpeBindingService::bindFromWorkOrder()),
            // only ever set by bindFromLegacyImport().
            $table->string('import_match_confidence')->nullable()->after('serial_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cpe_devices', function (Blueprint $table) {
            $table->dropColumn('import_match_confidence');
        });
    }
};
