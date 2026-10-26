<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel MIRROR status/visibilitas adapter ZTP (v0.23.8.1) — BUKAN penentu
 * class. Sumber kebenaran handler ada di kode (CpeAdapterRegistry /
 * OltAdapterRegistry, keputusan #1). Satu tabel + kolom `layer` (olt/cpe),
 * sesuai section G4 docs/omci/ztp-dynamic-config-design.md. Platform-level
 * (tanpa tenant_id) — matriks dukungan global, pola payment_gateway_channels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_adapters', function (Blueprint $table) {
            $table->id();
            $table->string('layer', 8);          // olt | cpe
            $table->string('vendor_key', 32);
            $table->string('pon_mode', 8)->nullable(); // gpon | epon (OLT saja; CPE null)
            $table->string('transport', 16);     // tr069 | omci_cli
            $table->boolean('is_supported')->default(false);
            $table->json('capabilities');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['layer', 'vendor_key', 'pon_mode'], 'provisioning_adapters_layer_vendor_pon_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_adapters');
    }
};
