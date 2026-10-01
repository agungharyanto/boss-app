<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.23.5 (revisi panel OMCI, keputusan Agung OPSI a) — Paket/VLAN,
 * PPPoE username & password TIDAK lagi disimpan terpisah di
 * test_onu_wan_configs. Semua DIDERIVE LIVE dari customer saat panel
 * dimuat / applyWanConfig() dipanggil:
 * - vlan_pppoe & framed_pool: customers.ppp_package_id -> NetworkProfileGroup
 *   -> interface_name (extractVlanFromInterfaceName) / customerIpPool.
 * - pppoe_username: {customers.cid}@ppp.bajastu.id.
 * - pppoe_password: konstanta sistem (TestCredentialSyncService::PPPOE_PASSWORD).
 *
 * Tabel ini tinggal menyimpan onu_mode/wan_mode/config_method (+ pivot
 * test_onu_attached_vlans) — tidak ada lagi salinan field turunan.
 *
 * Migration HANYA ubah skema — TIDAK menyentuh config ONU yang sudah
 * di-apply ke OLT (ONU_1/ONU_2 tetap aktif; radcheck tidak dihapus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_onu_wan_configs', function (Blueprint $table) {
            $table->dropForeign(['package_id']);
            $table->dropColumn(['package_id', 'vlan_pppoe', 'pppoe_username', 'pppoe_password']);
        });
    }

    public function down(): void
    {
        Schema::table('test_onu_wan_configs', function (Blueprint $table) {
            $table->foreignId('package_id')->nullable()->after('customer_id')
                ->constrained('ppp_packages')->nullOnDelete();
            $table->unsignedSmallInteger('vlan_pppoe')->nullable()->after('package_id');
            $table->string('pppoe_username')->nullable();
            $table->text('pppoe_password')->nullable();
        });
    }
};
