<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v0.12.3 — pivot "klaim" WorkOrder oleh Technician, GENUINELY terpisah
     * dari `work_orders.technician_id` (assignment resmi admin lewat
     * WorkOrderService::assignTechnician(), mengubah status WO ke
     * Assigned). Baris di sini murni penanda "teknisi X pernah klaim WO
     * ini lewat API" — tidak pernah ditulis oleh assignTechnician(), tidak
     * pernah mengubah status WO. unique(work_order_id, technician_id) —
     * klaim ulang oleh teknisi yang sama harus idempoten (lihat
     * WorkOrderController::claim()), bukan baris duplikat.
     */
    public function up(): void
    {
        Schema::create('work_order_technicians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('technicians')->cascadeOnDelete();
            $table->timestamp('claimed_at');
            $table->timestamps();

            $table->unique(['work_order_id', 'technician_id']);
            $table->index('technician_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_technicians');
    }
};
