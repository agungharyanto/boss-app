<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.16.0 — Core Network Infrastructure Management, Langkah 1. Foto per
 * titik topologi — `owner_type`/`owner_id` morph ke `fiber_nodes` ATAU
 * `odps` (dua tabel berbeda, tanpa FK constraint, pola sama dengan morph
 * lain di sprint ini — lihat create_fiber_nodes_table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiber_node_photos', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->string('photo_path');
            $table->string('caption')->nullable();
            $table->timestamp('taken_at')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiber_node_photos');
    }
};
