<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.16.0 — Core Network Infrastructure Management, Langkah 1. Segmen
 * kabel antar dua titik topologi (`from`/`to`, masing-masing morph ke
 * `fiber_nodes` ATAU `odps`, tanpa FK constraint — pola sama morph lain
 * di sprint ini).
 *
 * `total_cores` sengaja TIDAK divalidasi genap di level DB (tidak semua
 * driver DB — termasuk SQLite yang dipakai test suite — mendukung CHECK
 * constraint modulus dengan portabel) — validasi genap dilakukan di
 * Service/FormRequest, Langkah 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiber_cables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('from_type');
            $table->unsignedBigInteger('from_id');
            $table->string('to_type');
            $table->unsignedBigInteger('to_id');
            $table->unsignedInteger('total_cores');
            $table->unsignedInteger('tube_count');
            $table->unsignedInteger('cores_per_tube');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['from_type', 'from_id']);
            $table->index(['to_type', 'to_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiber_cables');
    }
};
