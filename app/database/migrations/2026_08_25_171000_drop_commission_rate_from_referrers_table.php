<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.9.2 — referrers.commission_rate is deprecated, superseded by a
 * per-package rate table planned for v0.9.3. Confirmed via grep before
 * writing this migration that no application code reads/writes it beyond
 * App\Models\Referrer's own $fillable/casts and ReferrerFactory's default
 * (both updated in this same commit) — no business logic depended on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrers', function (Blueprint $table) {
            $table->dropColumn('commission_rate');
        });
    }

    public function down(): void
    {
        Schema::table('referrers', function (Blueprint $table) {
            $table->decimal('commission_rate', 5, 2)->nullable();
        });
    }
};
