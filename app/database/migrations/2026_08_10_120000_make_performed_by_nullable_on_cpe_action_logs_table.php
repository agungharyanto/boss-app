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
        // v0.7.5 — auto-provisioning (CpeBindingService, triggered from a
        // work order binding or the scheduled reconciliation command) has no
        // human actor at all, unlike every other CpeActionLog writer so far
        // (all UI/API-triggered). Confirmed with Agung: nullable + "Sistem
        // (auto-provisioning)" in the UI is more honest than inventing a
        // fake system user that would then need excluding from user
        // pickers/admin lists everywhere else.
        Schema::table('cpe_action_logs', function (Blueprint $table) {
            $table->foreignId('performed_by')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cpe_action_logs', function (Blueprint $table) {
            $table->foreignId('performed_by')->nullable(false)->change();
        });
    }
};
