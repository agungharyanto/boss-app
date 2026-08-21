<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform-level, not tenant-scoped — same posture as
     * cpe_parameter_maps/payment_gateway_channels: a catalog of real-world
     * hardware vendors, not per-tenant data. Added from the UI (v0.8.1
     * OLT Credential Registry), never hardcoded.
     */
    public function up(): void
    {
        Schema::create('olt_manufacturers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('olt_manufacturers');
    }
};
