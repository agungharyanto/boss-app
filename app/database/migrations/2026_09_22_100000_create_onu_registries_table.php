<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.23.4 — Registry ONU, ON-DEMAND SAJA (tidak ada job berkala). Baris
 * HANYA pernah dibuat/diupdate lewat App\Services\Network\OnuRegistryService::
 * syncOnu() — cache hasil lookup terakhir ke OLT via sidecar (v0.23.3),
 * BUKAN salinan lengkap OLT, dan TIDAK PERNAH jadi sumber kebenaran untuk
 * operasi tulis (sama aturan keras yang sudah dikunci di desain sidecar
 * v0.23.3 §7 — lihat docs/omci/onu-registry-design.md).
 *
 * DUA kolom status TERPISAH, SENGAJA tidak dicampur (keputusan Agung
 * 2026-09-22, lihat docs/omci/onu-registry-design.md §keputusan):
 * - `status` — status ONU itu sendiri menurut OLT (working/offline/
 *   unconfigured/dll, App\Enums\OnuRegistryStatus).
 * - `sync_status` — status SINKRONISASI BOSS sendiri (apakah baris ini
 *   representasi terbaru/gagal-refresh, App\Enums\OnuSyncStatus) — TIDAK
 *   PERNAH diisi otomatis oleh job berkala (tidak ada job berkala di
 *   v0.23.4), murni hasil percobaan sync terakhir yang eksplisit dipicu.
 *
 * serial_number/mac_address SENGAJA TIDAK di-encrypt — level sensitivitas
 * sama dengan work_order_modem_units.serial_number/mac_address (sudah
 * plain di tabel itu sejak v0.13.4.1), dan perlu WHERE exact-match
 * langsung untuk lookup cepat (encrypted cast tidak mendukung itu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onu_registries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('olt_device_id')->constrained('olt_devices')->cascadeOnDelete();

            // Representasi identifier ONU dalam bentuk yang dipakai CLI
            // vendor (ZTE: format asli device, mis. "gpon-onu_1/3/12:2".
            // HSGQ: konvensi internal BOSS App, belum dipakai sampai
            // operasi per-ONU HSGQ TERUJI — lihat docs/omci/
            // onu-registry-design.md §5).
            $table->string('vendor_identifier');

            $table->string('serial_number')->nullable();
            $table->string('mac_address')->nullable();

            // App\Enums\OnuRegistryStatus — status ONU dari OLT. String
            // bebas (bukan DB enum kaku) supaya nilai vendor yang belum
            // dipetakan tidak pernah memaksa migration baru.
            $table->string('status', 32)->default('unknown');

            // App\Enums\OnuSyncStatus — status sinkronisasi BOSS sendiri,
            // KONSEP TERPISAH dari `status` di atas. Default DB
            // 'never_synced' murni safety-net (Service SELALU eksplisit
            // set 'synced'/'stale' saat baris benar-benar dibuat/diupdate
            // — lihat OnuRegistryService::syncOnu()).
            $table->string('sync_status', 20)->default('never_synced');

            // Mentah dari OLT (field Name/Description CLI), SEBELUM
            // format "Nama - CID" v0.23.5+ diterapkan.
            $table->string('name')->nullable();
            $table->string('description')->nullable();

            // Link opsional — lihat OnuRegistryService::findWorkOrderMatch().
            // Nullable, TIDAK wajib match.
            $table->foreignId('work_order_modem_unit_id')->nullable()
                ->constrained('work_order_modem_units')->nullOnDelete();

            $table->timestamp('last_synced_at')->nullable();

            // Hasil mentah operasi baca terakhir (field:value lengkap dari
            // sidecar, TANPA re-mask — lihat docs/omci/sidecar-design.md
            // §4 field mask_sensitive) untuk audit/debug. TIDAK PERNAH
            // dipakai sebagai sumber kebenaran untuk tulis.
            $table->json('raw_payload')->nullable();

            $table->timestamps();

            $table->unique(['olt_device_id', 'vendor_identifier']);
            $table->index('serial_number');
            $table->index('mac_address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onu_registries');
    }
};
