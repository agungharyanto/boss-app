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
        Schema::create('reseller_package_pricing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            // No base_package_id in v0.3.2 — no ISP package catalog table exists
            // yet anywhere in the codebase/roadmap. Every row here is therefore
            // an independent reseller-defined entry. is_custom stays as a plain
            // manual flag (reseller's own categorization in the UI, e.g. "bundle"
            // vs "plain package"), not derived from a base-package link. Once a
            // real `packages` catalog table exists, add base_package_id via a
            // separate migration and let is_custom regain its original meaning.
            $table->boolean('is_custom')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index('reseller_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reseller_package_pricing');
    }
};
