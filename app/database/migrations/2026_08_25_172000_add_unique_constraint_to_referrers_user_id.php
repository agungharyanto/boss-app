<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.9.2 — a Referrer's login account is meant to be 1:1 (one User can only
 * ever be linked to one Referrer's own portal login). Postgres allows
 * multiple NULLs through a plain unique index (only non-null values are
 * compared), so this stays nullable-and-unique — a Referrer with no login
 * account yet (user_id null) is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrers', function (Blueprint $table) {
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('referrers', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
        });
    }
};
