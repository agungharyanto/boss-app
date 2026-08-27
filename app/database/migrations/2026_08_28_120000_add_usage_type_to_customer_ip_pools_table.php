<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.14.3.1 — real bug found by Agung: nothing separated a PPP-only pool
 * from a Hotspot-only pool in the Grup Profil form's own IP Pool
 * dropdown. Default 'general' for existing rows — deliberately NOT
 * guessed from the pool's own name (e.g. "Hotspot-10Mbps" looks obvious,
 * but a guess that's wrong is worse than an honest "Umum" the admin can
 * correct by hand) — see App\Enums\CustomerIpPoolUsageType.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_ip_pools', function (Blueprint $table) {
            $table->string('usage_type')->default('general')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('customer_ip_pools', function (Blueprint $table) {
            $table->dropColumn('usage_type');
        });
    }
};
