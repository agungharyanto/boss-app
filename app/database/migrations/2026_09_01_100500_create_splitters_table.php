<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.16.0 — Core Network Infrastructure Management, Langkah 1. Splitter
 * yang menempel pada satu titik topologi (`owner_type`/`owner_id` morph
 * ke `fiber_nodes` ATAU `odps`, tanpa FK constraint — pola sama morph
 * lain di sprint ini). `ratio` sengaja string bebas (mis. "1:8", "1:16")
 * — bukan enum, karena rasio splitter nyata bervariasi dan tidak semua
 * kombinasi diketahui di muka.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('splitters', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->string('ratio');
            $table->string('model')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('splitters');
    }
};
