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
            // Member id from the legacy MixRadius system this customer was
            // imported/matched from — nullable (most customers have no
            // legacy origin), globally unique (a MixRadius member id is a
            // single external system's own identifier, not something that
            // could ever legitimately repeat under a different tenant).
            $table->string('legacy_mixradius_member_id')->nullable()->unique()->after('cid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['legacy_mixradius_member_id']);
            $table->dropColumn('legacy_mixradius_member_id');
        });
    }
};
