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
        Schema::table('customers', function (Blueprint $table) {
            // Nullable: existing rows (and the rare case where a code
            // couldn't be resolved yet) have no cid until
            // CustomerIdGeneratorService backfills/generates one.
            // Auto-generated only (CustomerObserver::created(), via
            // CustomerIdGeneratorService) — never fillable, never
            // accepted from any request.
            $table->string('cid')->nullable()->after('id');
            $table->unique(['tenant_id', 'cid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'cid']);
            $table->dropColumn('cid');
        });
    }
};
