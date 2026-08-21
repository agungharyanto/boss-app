<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform-level, same posture as olt_manufacturers above — a model
     * name only makes sense scoped to its manufacturer (unique per pair,
     * not globally), added from the UI.
     */
    public function up(): void
    {
        Schema::create('olt_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('olt_manufacturer_id')->constrained('olt_manufacturers')->cascadeOnDelete();
            $table->string('name');
            $table->string('supported_pon_type');

            $table->timestamps();

            $table->unique(['olt_manufacturer_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('olt_models');
    }
};
