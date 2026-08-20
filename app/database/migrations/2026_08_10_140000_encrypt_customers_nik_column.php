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
            // Deterministic HMAC of the plaintext NIK (see Customer::booted()
            // and config('app.nik_hmac_key')) — lets us keep a unique-per-tenant
            // NIK lookup even though `nik` itself becomes a randomized
            // `encrypted` cast below (same plaintext encrypts differently
            // every time, so it can never be queried/compared directly).
            $table->string('nik_hash')->nullable()->after('nik');
        });

        Schema::table('customers', function (Blueprint $table) {
            // varchar(255) was enough for a raw 16-digit NIK but not with
            // comfortable headroom for Laravel's encrypted-cast payload
            // (~228 chars for a 16-digit value at time of writing) — text
            // avoids depending on that number staying below 255 forever.
            $table->text('nik')->nullable()->change();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'nik']);
            $table->unique(['tenant_id', 'nik_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'nik_hash']);
            $table->unique(['tenant_id', 'nik']);
        });

        Schema::table('customers', function (Blueprint $table) {
            // Best-effort revert only — if any row's `nik` already holds an
            // encrypted payload longer than 255 chars, this silently
            // truncates it. Fine while customers is empty (confirmed at the
            // time this migration was written); re-check before relying on
            // this down() against real data.
            $table->string('nik')->nullable()->change();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('nik_hash');
        });
    }
};
