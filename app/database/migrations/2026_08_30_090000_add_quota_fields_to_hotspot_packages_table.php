<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.14.4 amendment — real gap confirmed by Agung via screenshot: the form
 * was missing "Kuota"/"Satuan Data" fields for limit_type=QuotaBase
 * packages (MixRadius-reference UI has them; Masa Aktif alone conflates
 * two different concepts — WHEN a package expires vs HOW MUCH data it
 * allows).
 *
 * Per Langkah 0 investigation (see CLAUDE.md's own v0.14.4 amendment
 * section): quota_value/quota_unit have NO RouterOS `/ip hotspot user
 * profile`-level push target — confirmed again empirically (a real
 * add/read/remove round trip against ro-hotspot.bajastu.id) that byte
 * quota only exists on `/ip hotspot user` (an individual voucher/user
 * object, via `limit-bytes-total`), which doesn't exist yet (voucher
 * generation is later scope). These two columns are therefore stored for
 * that future feature to read — HotspotPackageService/
 * PushHotspotPackageToMikrotikJob deliberately do NOT reference them at
 * all this sub-version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotspot_packages', function (Blueprint $table) {
            $table->decimal('quota_value', 10, 2)->nullable()->after('active_duration_unit');
            $table->string('quota_unit')->nullable()->after('quota_value');
        });
    }

    public function down(): void
    {
        Schema::table('hotspot_packages', function (Blueprint $table) {
            $table->dropColumn(['quota_value', 'quota_unit']);
        });
    }
};
