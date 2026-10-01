<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.23.5 Bagian D — mapping customer -> ONU fisik, HANYA relevan untuk
 * customer is_test_fixture=true (aktivasi OMCI uji end-to-end). Bukan
 * kolom `onu_registries.customer_id` (desain itu SENGAJA dikunci v0.23.4
 * sebagai murni cache read-only per olt_device_id+vendor_identifier,
 * tidak pernah jadi sumber kebenaran tulis dan tidak pernah tahu tentang
 * Customer — lihat docs/omci/onu-registry-design.md). Kolom ini sebalik-
 * nya: murni untuk TestCredentialSyncService tahu HARUS menyentuh ONU
 * mana untuk customer test tertentu — `{olt_device_id, pon_interface,
 * onu_id, sn}`, cukup untuk memanggil OltSidecarClient tanpa perlu
 * OnuRegistry sama sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->json('test_onu_metadata')->nullable()->after('is_test_fixture');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('test_onu_metadata');
        });
    }
};
