<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profil provisioning ZTP (v0.23.8.3) — bundle bernama berisi 1 WAN Internet
 * (wajib) + 0..1 WAN Bridge + (nanti) 0..N SSID. Tenant-scoped (admin-managed),
 * di-resolve on-demand per aktivasi. Section D2 docs/omci/ztp-dynamic-config-design.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_profiles');
    }
};
