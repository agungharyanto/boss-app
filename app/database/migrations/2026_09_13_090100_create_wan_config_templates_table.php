<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.12.4 — cluster Template Konfig CPE. Menggantikan `remote_wan_configs`
 * (singleton, satu konfigurasi fleet-wide) dengan MATRIX Paket × Tipe
 * Modem — satu baris = satu kombinasi `ppp_package_id` + `modem_type_id`.
 *
 * `remote_wan_configs` SENGAJA TIDAK dihapus/dimatikan di sub-versi ini —
 * tabel ini dibangun PARALEL. Migrasi data live (kalau ada) ke skema baru
 * adalah langkah terpisah setelah desain ini teruji.
 *
 * `modem_type_id` NULLABLE — NULL = template default/fallback untuk paket
 * itu, berlaku apa pun modemnya (dipakai untuk device lama yang belum
 * punya `work_order_devices.modem_type_id` tercatat, atau paket yang belum
 * butuh differensiasi per-modem). `restrictOnDelete()` pada KEDUA FK —
 * baik PppPackage maupun ModemType yang masih dipakai template AKTIF tidak
 * boleh terhapus diam-diam (beda dari `work_order_devices.modem_type_id`
 * yang nullOnDelete — itu catatan historis instalasi, ini konfigurasi
 * provisioning yang live dipakai).
 *
 * TIDAK ADA kolom serial-allowlist manual (beda dari `remote_wan_configs`
 * lama) — SN yang di-scope preset GenieACS per-template dihitung DINAMIS
 * saat sync (cross-reference `customers.ppp_package_id` +
 * `work_order_devices.modem_type_id`), bukan diketik manual admin. Logic
 * hitungnya sendiri: v0.12.5 (GenieAcsPresetService baru), bukan sub-versi
 * ini — sub-versi ini murni skema.
 *
 * Dua lapis unique constraint untuk `(ppp_package_id, modem_type_id)`:
 * 1. Unique index Laravel biasa menegakkan kombinasi non-NULL dengan
 *    benar (Postgres unique index membandingkan nilai non-NULL secara
 *    normal).
 * 2. Partial unique index TERPISAH untuk kasus `modem_type_id IS NULL` —
 *    unique constraint standar SQL memperlakukan NULL sebagai TIDAK SAMA
 *    dengan NULL lain, jadi tanpa index kedua ini satu `ppp_package_id`
 *    bisa punya BANYAK baris "default" (modem_type_id NULL) sekaligus,
 *    yang melanggar makna "NULL = SATU fallback untuk paket ini".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wan_config_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ppp_package_id')->constrained('ppp_packages')->restrictOnDelete();
            $table->foreignId('modem_type_id')->nullable()->constrained('modem_types')->restrictOnDelete();

            // Master switch per-template — sama makna `remote_wan_configs.enabled`
            // tapi sekarang per-baris, bukan fleet-wide.
            $table->boolean('enabled')->default(false);

            $table->boolean('wan1_enabled')->default(true);
            $table->unsignedSmallInteger('wan1_vlan')->default(1000);
            $table->string('wan1_pppoe_username')->default('default');
            $table->string('wan1_pppoe_password')->default('default');

            $table->boolean('wan2_enabled')->default(false);
            $table->unsignedSmallInteger('wan2_vlan')->default(1200);

            // Jejak sinkronisasi ke GenieACS per-template — pola sama
            // `remote_wan_configs.genieacs_sync_*` / `mikrotik_sync_*` di
            // cluster Profil Paket.
            $table->string('genieacs_sync_status')->default('pending'); // pending | synced | failed
            $table->timestamp('genieacs_synced_at')->nullable();
            $table->text('genieacs_sync_error')->nullable();

            $table->timestamps();

            $table->index('tenant_id');
            $table->index('ppp_package_id');
            $table->index('modem_type_id');

            $table->unique(['ppp_package_id', 'modem_type_id'], 'wan_config_templates_pkg_modem_unique');
        });

        DB::statement(
            'CREATE UNIQUE INDEX wan_config_templates_pkg_null_modem_unique '.
            'ON wan_config_templates (ppp_package_id) WHERE modem_type_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('wan_config_templates');
    }
};
