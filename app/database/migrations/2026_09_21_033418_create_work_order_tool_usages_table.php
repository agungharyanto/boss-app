<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.4.1 — alat NON-modem yang dicatat dibawa untuk sebuah Work Order
 * (dropcore, adapter, patchcore, dst — dipilih dari `tool_types`), per
 * jumlah + siapa bawa. Murni catatan per-WO, TIDAK ADA stok/deduction
 * inventori (benih minimal untuk modul Gudang yang masih backlog terpisah
 * — jangan dibangun sekarang, lihat instruksi kickoff).
 *
 * `technician_id` nullable + nullOnDelete() (BUKAN cascadeOnDelete seperti
 * work_order_claim_partners/work_order_technicians) — ini catatan histori
 * "siapa bawa alat apa", sama filosofi preservasi histori seperti
 * work_orders.technician_id sendiri; kalau baris Technician suatu saat
 * dihapus, catatan alat TIDAK ikut hilang, cuma kehilangan info "siapa"-nya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_tool_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('tool_type_id')->constrained('tool_types')->restrictOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('technicians')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->index('work_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_tool_usages');
    }
};
