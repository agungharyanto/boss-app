<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.12.4 — cluster Template Konfig CPE (matrix Paket × Tipe Modem).
 *
 * Tipe Modem BUKAN hasil deteksi otomatis dari telemetry TR-069
 * (`cpe_devices.manufacturer`/`model_name` — dikonfirmasi lewat investigasi
 * langsung ke data real: `model_name` NULL di 92,5% dari 414 device,
 * `manufacturer` yang terisi cuma kode OUI mentah GenieACS, bukan kategori
 * vendor-template) — melainkan FIELD YANG DIISI MANUAL teknisi saat
 * instalasi (sama seperti dia input SSID+Password), dipilih dari daftar
 * yang dikelola admin di sini. Lihat `work_order_devices.modem_type_id`
 * (migration terpisah, sama sprint) untuk titik input teknisi-nya.
 *
 * Tenant-scoped, sama posture seluruh cluster "Profil Paket"
 * (bandwidth_profiles/network_profile_groups/ppp_packages/hotspot_packages)
 * — BUKAN platform-level seperti olt_manufacturers/olt_models (yang murni
 * katalog hardware fisik lintas-ISP); Tipe Modem di sini terikat ke katalog
 * paket ISP yang sama, jadi ikut tenant.
 *
 * UI CRUD-nya sendiri (dropdown admin) menyusul v0.12.5 — tabel ini dulu,
 * diisi lewat tinker/seeder untuk kebutuhan sekarang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modem_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
        });

        // name unik per tenant, hanya di antara baris yang belum
        // soft-deleted — sama pola bandwidth_profiles/ppp_packages.
        DB::statement(
            'CREATE UNIQUE INDEX modem_types_tenant_id_name_unique '.
            'ON modem_types (tenant_id, name) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('modem_types');
    }
};
