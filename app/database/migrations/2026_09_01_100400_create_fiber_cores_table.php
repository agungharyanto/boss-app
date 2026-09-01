<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.16.0 — Core Network Infrastructure Management, Langkah 1. Satu baris
 * per core fisik dalam satu tube di dalam satu fiber_cables — satu-
 * satunya tabel di sprint ini dengan FK nyata (bukan morph), karena
 * parent-nya (fiber_cables) tunggal, tidak polimorfik.
 *
 * `tube_color`/`core_color` NULLABLE — override manual; warna default
 * (TIA/EIA-598-C, auto-derive dari tube_number/core_number_in_tube)
 * dihitung di Model/Service Langkah 2, bukan disimpan di sini kecuali
 * di-override eksplisit oleh teknisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiber_cores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiber_cable_id')->constrained('fiber_cables')->cascadeOnDelete();
            $table->unsignedInteger('tube_number');
            $table->unsignedInteger('core_number_in_tube');
            $table->string('tube_color')->nullable();
            $table->string('core_color')->nullable();
            // App\Enums\FiberCoreStatus (used/spare) — dibangun Langkah 2.
            $table->string('status')->default('spare');
            $table->timestamps();

            $table->unique(['fiber_cable_id', 'tube_number', 'core_number_in_tube']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiber_cores');
    }
};
