<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.4.1 — master data alat (non-modem) yang bisa dipilih teknisi saat
 * mencatat apa yang dia bawa untuk sebuah Work Order. Tenant-scoped (setiap
 * ISP tenant punya daftar alat sendiri, pola sama bandwidth_profiles/
 * hotspot_packages — bukan platform-level seperti payment_gateway_channels).
 * Admin CRUD sendiri lewat UI (App\Livewire\Installation\ToolTypeIndex) —
 * seed 4 item awal per tenant (ToolTypeSeeder), bukan daftar tertutup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_types');
    }
};
