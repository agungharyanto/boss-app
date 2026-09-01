<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.16.0 — Core Network Infrastructure Management, Langkah 1. Aksesori
 * (pin adaptor/connector/splice) yang menempel pada SATU kabel ATAU SATU
 * splitter — bukan morph (cuma 2 kemungkinan target, keduanya sudah
 * tabel nyata dengan FK-nya sendiri), tapi dua FK nullable terpisah;
 * "salah satu wajib diisi" divalidasi di Service layer (Langkah 2), BUKAN
 * DB constraint (CHECK constraint XOR portabel tidak trivial lintas
 * SQLite/Postgres, sama alasan `total_cores` genap di fiber_cables).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiber_accessories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiber_cable_id')->nullable()->constrained('fiber_cables')->cascadeOnDelete();
            $table->foreignId('splitter_id')->nullable()->constrained('splitters')->cascadeOnDelete();
            // App\Enums\FiberAccessoryType (pin_adaptor/connector/
            // splice_fusion/splice_mechanical) — dibangun Langkah 2.
            $table->string('accessory_type');
            $table->decimal('expected_loss_db', 6, 2)->nullable();
            $table->decimal('measured_loss_db', 6, 2)->nullable();
            $table->string('location_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiber_accessories');
    }
};
