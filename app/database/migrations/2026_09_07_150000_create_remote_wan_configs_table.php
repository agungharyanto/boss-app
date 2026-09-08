<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Konfig Remote" — GenieACS Auto-WAN configurable (branch
 * genieacs-auto-wan-configurable).
 *
 * Platform-level singleton (id=1, sama posture `payment_gateway_settings`
 * / `cpe_parameter_maps` — bukan per-tenant/reseller: GenieACS memonitor
 * infra ISP-nya sendiri, provision "default" berlaku fleet-wide). Nilai di
 * sini menggantikan `const targetVlan = 1000` / `targetVlanWan2 = 1200`
 * yang HARDCODED di provision script referensi (auto_setup_wan_pppoe /
 * auto_setup_wan_bridge) — sekarang dilewatkan sebagai `args` preset
 * GenieACS ke provision `default-wan` yang statis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remote_wan_configs', function (Blueprint $table) {
            $table->id();

            // Master switch — kalau false, provision `default-wan` tidak
            // pernah masuk konfigurasi preset `default` sama sekali
            // (GenieACS tidak menyentuh WAN perangkat mana pun).
            $table->boolean('enabled')->default(false);

            // WAN1 = koneksi internet PPPoE (auto_setup_wan_pppoe).
            // Guard idempoten di script: skip kalau WANPPPConnection.1.Username
            // SUDAH terisi di perangkat — jadi ~aman untuk pelanggan existing,
            // hanya ONT baru/factory-reset yang benar-benar dikonfigurasi.
            $table->boolean('wan1_enabled')->default(true);
            $table->unsignedSmallInteger('wan1_vlan')->default(1000);
            $table->string('wan1_pppoe_username')->default('default');
            $table->string('wan1_pppoe_password')->default('default');

            // WAN2 = koneksi bridge kedua (auto_setup_wan_bridge, IPTV/
            // layanan lain). Guard idempoten mengecek instance KE-2
            // (WAN...2.ConnectionType) — 0 perangkat di fleet punya ini
            // sekarang, jadi mengaktifkan = perubahan fleet-wide ke ~400
            // ONT. Default OFF, dinyalakan sadar oleh admin.
            $table->boolean('wan2_enabled')->default(false);
            $table->unsignedSmallInteger('wan2_vlan')->default(1200);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // Jejak sinkronisasi ke GenieACS (pola sama `mikrotik_sync_*`
            // di cluster Profil Paket).
            $table->string('genieacs_sync_status')->default('pending'); // pending | synced | failed
            $table->timestamp('genieacs_synced_at')->nullable();
            $table->text('genieacs_sync_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_wan_configs');
    }
};
