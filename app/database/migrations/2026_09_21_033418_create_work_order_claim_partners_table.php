<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.4.1 — partner kerja yang dipilih teknisi UTAMA (pengklik signed-link
 * klaim) saat mengklaim sebuah Work Order. SENGAJA tabel BARU, TERPISAH dari
 * `work_order_technicians` (v0.12.3) — tabel itu semantiknya sudah
 * established sebagai "teknisi mana saja pernah klaim WO ini lewat API
 * Sanctum" (self-service browsing, TIDAK ada konsep "partner resmi"),
 * reuse akan ambigu tanpa kolom pembeda sumber. Boleh lebih dari 1 partner
 * per WO, tanpa batas jumlah (dikonfirmasi eksplisit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_claim_partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('technicians')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['work_order_id', 'technician_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_claim_partners');
    }
};
