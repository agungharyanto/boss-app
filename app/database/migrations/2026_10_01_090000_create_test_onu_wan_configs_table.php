<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.23.5 (Opsi B) — konfigurasi WAN test per customer `is_test_fixture`,
 * model "1 WAN aktif + Attached VLANs" (gaya SmartOLT). MENGGANTIKAN
 * pendekatan JSON `customers.test_onu_metadata` untuk konfigurasi WAN
 * (metadata pemetaan fisik ONU tetap di `test_onu_metadata`; tabel ini
 * HANYA konfigurasi WAN yang bisa diubah admin: paket/mode/metode).
 *
 * TEST-ONLY — Service (TestCredentialSyncService) menolak keras customer
 * selain is_test_fixture=true. Bukan tabel produksi.
 *
 * `pppoe_password` di-`encrypted` cast di model (pola sama OltDevice.
 * telnet_password/ssh_password) — kolom `text` karena ciphertext lebih
 * panjang dari plaintext.
 *
 * Pivot `test_onu_attached_vlans`: VLAN mana saja yang DIIZINKAN LEWAT ke
 * ONU (level flow/vlan-filter permission, BUKAN WAN kedua). VLAN 9
 * (remote management) SELALU disertakan default dan TIDAK bisa dihapus —
 * di-enforce di Service (TestCredentialSyncService), bukan hanya UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_onu_wan_configs', function (Blueprint $table) {
            $table->id();
            // 1:1 per customer test — satu WAN aktif pada satu waktu
            // (keterbatasan hardware/SmartOLT, lihat docs/omci/
            // onu-test-ui-design.md §0).
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();

            // Paket yang dipilih → VLAN PPPoE diturunkan dari
            // NetworkProfileGroup.interface_name saat apply, tapi di-snapshot
            // di `vlan_pppoe` supaya tetap konsisten kalau grup berubah.
            $table->foreignId('package_id')->nullable()
                ->constrained('ppp_packages')->nullOnDelete();
            $table->unsignedSmallInteger('vlan_pppoe')->nullable();

            // String DB (bukan DB-enum kaku), divalidasi di PHP enum
            // (App\Enums\TestOnuMode / TestOnuWanMode / TestOnuConfigMethod).
            // onu_mode: hanya 'routing' aktif v1 (bridging ditunda).
            $table->string('onu_mode', 16)->default('routing');
            // wan_mode: hanya 'pppoe' yang flow eksekusinya diimplementasikan
            // v1; 'dhcp'/'static'/'webpage' boleh tersimpan (pilihan UI) tapi
            // apply-nya belum ada ("Segera hadir").
            $table->string('wan_mode', 16)->default('pppoe');
            // config_method: 'omci' (template delete+recreate terbukti) atau
            // 'tr069' (reuse RemoteWanConfig/GenieACS existing).
            $table->string('config_method', 16)->default('omci');

            $table->string('pppoe_username')->nullable();
            // encrypted cast di model — ciphertext > plaintext, pakai text.
            $table->text('pppoe_password')->nullable();

            $table->timestamps();
        });

        Schema::create('test_onu_attached_vlans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_onu_wan_config_id')
                ->constrained('test_onu_wan_configs')->cascadeOnDelete();
            // Opsional — VLAN 9 (remote mgmt) & VLAN mentah tidak selalu
            // punya grup profil pelanggan.
            $table->foreignId('network_profile_group_id')->nullable()
                ->constrained('network_profile_groups')->nullOnDelete();
            // Nilai VLAN adalah sumber kebenaran pivot (grup opsional).
            $table->unsignedSmallInteger('vlan_id');

            // Satu VLAN tidak boleh dobel per config.
            $table->unique(['test_onu_wan_config_id', 'vlan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_onu_attached_vlans');
        Schema::dropIfExists('test_onu_wan_configs');
    }
};
